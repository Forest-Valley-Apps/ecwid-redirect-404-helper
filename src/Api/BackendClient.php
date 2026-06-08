<?php
/**
 * Hosted-backend HTTP client.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client over the hosted Redirect & 404 Manager backend.
 *
 * Talks to the four **public** read/report endpoints only — never the
 * privileged admin write API (those endpoints sit behind Ecwid-iframe auth):
 *  - `GET  /api/storefront/rules/:storeId`   — the resolved redirect ruleset.
 *  - `GET  /api/storefront/deleted/:storeId` — webhook-sourced deleted entity ids.
 *  - `POST /api/storefront/404`              — report a captured 404.
 *  - `POST /api/storefront/hit`              — report a redirect hit.
 *
 * The rules endpoint always serves the **full** ruleset inline
 * (`{ v, exact, wildcard, storeUrl?, baseUrl? }`); the backend resolves the
 * 256KB "overflow" marker server-side, so this client never has to follow an
 * overflow `endpoint`. The empty/no-rules answer is `{ v:2, exact:[], wildcard:[] }`.
 * Should an overflow-shaped body ever arrive (defensive), it is treated as
 * "no inline rules" rather than parsed as a ruleset.
 *
 * The two report calls are fire-and-forget: non-blocking with a short timeout
 * so a slow or erroring backend can never delay a page render.
 */
final class BackendClient {

	/**
	 * Production backend base URL.
	 *
	 * @var string
	 */
	public const PROD_BASE_URL = 'https://redirect-manager-prod.up.railway.app';

	/**
	 * Staging backend base URL.
	 *
	 * @var string
	 */
	public const STAGING_BASE_URL = 'https://redirect-manager-dev.up.railway.app';

	/**
	 * Transient key prefix for the cached ruleset (store id is appended).
	 *
	 * @var string
	 */
	private const RULES_TRANSIENT_PREFIX = 'fv_erh_rules_';

	/**
	 * How long, in seconds, to cache a fetched ruleset.
	 *
	 * @var int
	 */
	private const RULES_CACHE_TTL = 900;

	/**
	 * Transient key prefix for the cached deleted-entity ids (store id appended).
	 *
	 * @var string
	 */
	private const DELETED_TRANSIENT_PREFIX = 'fv_erh_deleted_';

	/**
	 * The deleted-entities endpoint's 404 marker for "store never installed
	 * the hosted app" — the wire contract that separates that definite answer
	 * from a meaningless routing 404 (see `docs/parent-product-tasks.md` Part 1).
	 *
	 * @var string
	 */
	public const ERROR_STORE_NOT_TRACKED = 'store-not-tracked';

	/**
	 * Transient key prefix for the cached app-installed flag (store id appended).
	 *
	 * @var string
	 */
	private const APP_STATUS_TRANSIENT_PREFIX = 'fv_erh_app_status_';

	/**
	 * How long, in seconds, to cache the app-installed flag.
	 *
	 * The backend serves this with a 300s Redis TTL; the plugin holds it longer
	 * because install state changes rarely and the CTA destination tolerates lag.
	 *
	 * @var int
	 */
	private const APP_STATUS_CACHE_TTL = 3600;

	/**
	 * How long, in seconds, to cache the deleted-entity ids.
	 *
	 * Deletions are rare events and the verdict checker tolerates lag, so this
	 * is cached longer than the ruleset.
	 *
	 * @var int
	 */
	private const DELETED_CACHE_TTL = 3600;

	/**
	 * Timeout, in seconds, for the (blocking) rules fetch.
	 *
	 * @var int
	 */
	private const RULES_TIMEOUT = 5;

	/**
	 * Timeout, in seconds, for the fire-and-forget report calls.
	 *
	 * @var int
	 */
	private const REPORT_TIMEOUT = 1;

	/**
	 * Max length the backend accepts for a reported path/referrer field.
	 *
	 * Mirrors the backend's validation (`z.string().max(2048)`); an over-long
	 * value would fail validation and — because reports are non-blocking and
	 * the response is never read — be silently dropped. Clamp instead.
	 *
	 * @var int
	 */
	private const MAX_REPORT_FIELD = 2048;

	/**
	 * Ecwid store id this client reports for.
	 *
	 * @var int
	 */
	private int $store_id;

	/**
	 * Backend base URL (no trailing slash).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param int    $store_id Ecwid store id.
	 * @param string $base_url Backend base URL. Defaults to production.
	 */
	public function __construct( int $store_id, string $base_url = self::PROD_BASE_URL ) {
		$this->store_id = $store_id;
		$this->base_url = untrailingslashit( $base_url );
	}

	/**
	 * Build a client for a store against the site-configured base URL.
	 *
	 * The canonical way to construct a client outside of tests — every feature
	 * (settings screen, 404 capture, …) gets the same filtered base URL.
	 *
	 * @param int $store_id Ecwid store id.
	 * @return self
	 */
	public static function for_store( int $store_id ): self {
		/**
		 * Filter the hosted-backend base URL (e.g. to point at staging).
		 *
		 * @param string $base_url Default production base URL.
		 */
		$base_url = (string) apply_filters( 'fv_erh_backend_base_url', self::PROD_BASE_URL );

		return new self( $store_id, $base_url );
	}

	/**
	 * Fetch the resolved redirect ruleset for this store.
	 *
	 * The result is cached in a transient; on any transport/parse error the
	 * empty fallback is returned and **not** cached, so the next call retries.
	 *
	 * @param bool $force_refresh Bypass and refresh the transient cache.
	 * @return array{v:int,exact:array<int,array<string,mixed>>,wildcard:array<int,array<string,mixed>>,storeUrl?:string,baseUrl?:string}
	 */
	public function get_rules( bool $force_refresh = false ): array {
		$cache_key = $this->rules_transient_key();

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$rules = $this->fetch_rules();
		if ( null === $rules ) {
			// Transport or parse failure: serve empty, do not cache, retry next time.
			return $this->empty_rules();
		}

		set_transient( $cache_key, $rules, self::RULES_CACHE_TTL );

		return $rules;
	}

	/**
	 * Probe whether the backend is answering for this store right now.
	 *
	 * Unlike {@see self::get_rules()} — which returns the empty fallback for both
	 * a transport failure and a genuinely rule-less store, and so can't tell them
	 * apart — this performs an uncached fetch and reports whether the backend
	 * actually answered with a valid ruleset (HTTP 200 + parseable shape).
	 *
	 * Note: the backend serves an empty ruleset for *any* well-formed store id —
	 * including ones it has never seen — so a true result proves the service is
	 * reachable and answering, not that the store is registered there.
	 *
	 * On success the fetched ruleset is stored in the transient cache, so a
	 * connect/refresh flow needs no second fetch. The cache is never read.
	 *
	 * @return bool True when the rules endpoint answered with a valid ruleset.
	 */
	public function ping(): bool {
		$rules = $this->fetch_rules();
		if ( null === $rules ) {
			return false;
		}

		set_transient( $this->rules_transient_key(), $rules, self::RULES_CACHE_TTL );

		return true;
	}

	/**
	 * Force a fresh fetch of the ruleset, replacing the cached copy.
	 *
	 * @return array<string,mixed>
	 */
	public function refresh_rules(): array {
		return $this->get_rules( true );
	}

	/**
	 * Delete the cached ruleset for this store.
	 *
	 * @return void
	 */
	public function clear_rules_cache(): void {
		delete_transient( $this->rules_transient_key() );
	}

	/**
	 * Fetch the webhook-sourced deleted-entity ids for this store.
	 *
	 * The hosted app receives Ecwid `*.deleted` webhooks for every store that
	 * installed it, which is the authoritative deleted-vs-never-existed signal
	 * the public catalog API cannot provide. This endpoint is public read-only,
	 * like the ruleset.
	 *
	 * The answer is a tri-state, so the verdict checker can stay honest:
	 *  - `tracked === true`  — the store is known to the backend; `products` /
	 *    `categories` list the entity ids deleted since the app was installed.
	 *  - `tracked === false` — HTTP 404 with the endpoint's marker body: the
	 *    store never installed the hosted app, so no deletion history exists.
	 *  - `null`              — transport/status/parse error; indeterminate, not
	 *    cached, retried on the next call.
	 *
	 * @param bool $force_refresh Bypass and refresh the transient cache.
	 * @return array{tracked:bool,products:array<int,int>,categories:array<int,int>}|null
	 */
	public function get_deleted_entities( bool $force_refresh = false ): ?array {
		$cache_key = $this->deleted_transient_key();

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = $this->fetch_deleted_entities();
		if ( null === $result ) {
			return null;
		}

		set_transient( $cache_key, $result, self::DELETED_CACHE_TTL );

		return $result;
	}

	/**
	 * Delete the cached deleted-entity ids for this store.
	 *
	 * @return void
	 */
	public function clear_deleted_cache(): void {
		delete_transient( $this->deleted_transient_key() );
	}

	/**
	 * Whether the hosted app is installed for this store, fetching if needed.
	 *
	 * Reads the public app-status endpoint (`{ v, installed }`). The result is a
	 * tri-state so callers can fall back honestly:
	 *  - `true`  — the app is installed right now.
	 *  - `false` — definitely not installed (the backend's single-200 shape also
	 *    answers `false` for a store it has never seen; a 404 marker body, if one
	 *    ever appears, is treated the same way).
	 *  - `null`  — transport/status/parse error, or the route is not deployed yet;
	 *    indeterminate, not cached, retried next time.
	 *
	 * **Not render-safe** — this can perform a blocking request on a cache miss.
	 * Page renders must use {@see self::peek_app_installed()} instead; this is for
	 * the background cron warm and explicit refreshes.
	 *
	 * @param bool $force_refresh Bypass and refresh the transient cache.
	 * @return bool|null
	 */
	public function get_app_installed( bool $force_refresh = false ): ?bool {
		if ( ! $force_refresh ) {
			$cached = $this->peek_app_installed();
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$installed = $this->fetch_app_installed();
		if ( null === $installed ) {
			return null;
		}

		// Wrapped in an array so a cached `false` is distinguishable from the
		// `false` that get_transient() returns for a missing key.
		set_transient( $this->app_status_transient_key(), array( 'installed' => $installed ), self::APP_STATUS_CACHE_TTL );

		return $installed;
	}

	/**
	 * The cached app-installed flag without touching the network.
	 *
	 * Render-safe: returns the transient value, or null when nothing is cached
	 * (so the caller can fall back rather than block on a fetch).
	 *
	 * @return bool|null
	 */
	public function peek_app_installed(): ?bool {
		$cached = get_transient( $this->app_status_transient_key() );

		return ( is_array( $cached ) && isset( $cached['installed'] ) ) ? (bool) $cached['installed'] : null;
	}

	/**
	 * The cached deleted-endpoint tracked flag without touching the network.
	 *
	 * Render-safe proxy for "the app was installed at some point": reads the
	 * deleted-entities transient (warmed by the verdict feature) and returns its
	 * tracked state, or null when nothing is cached.
	 *
	 * @return bool|null
	 */
	public function peek_deleted_tracked(): ?bool {
		$cached = get_transient( $this->deleted_transient_key() );

		return ( is_array( $cached ) && isset( $cached['tracked'] ) ) ? (bool) $cached['tracked'] : null;
	}

	/**
	 * Report a captured 404 to the backend (fire-and-forget).
	 *
	 * @param string      $url_path The 404'd request path (1-2048 chars).
	 * @param string|null $referrer Optional referrer URL.
	 * @return void
	 */
	public function report_404( string $url_path, ?string $referrer = null ): void {
		if ( '' === $url_path ) {
			// The backend requires a non-empty path; nothing to report.
			return;
		}

		$body = array(
			'storeId' => $this->store_id,
			'urlPath' => $this->clamp_field( $url_path ),
		);

		if ( null !== $referrer && '' !== $referrer ) {
			$body['referrer'] = $this->clamp_field( $referrer );
		}

		$this->report( '/api/storefront/404', $body );
	}

	/**
	 * Report a redirect hit to the backend (fire-and-forget).
	 *
	 * @param string $source_path The source path that matched a rule.
	 * @return void
	 */
	public function report_hit( string $source_path ): void {
		if ( '' === $source_path ) {
			// The backend requires a non-empty path; nothing to report.
			return;
		}

		$this->report(
			'/api/storefront/hit',
			array(
				'storeId'    => $this->store_id,
				'sourcePath' => $this->clamp_field( $source_path ),
			)
		);
	}

	/**
	 * Clamp a report field to the backend's maximum accepted length.
	 *
	 * `NotFoundLog::clamp()` deliberately mirrors this so the stored and the
	 * reported path are always the same string.
	 *
	 * @param string $value Field value.
	 * @return string
	 */
	private function clamp_field( string $value ): string {
		if ( mb_strlen( $value ) <= self::MAX_REPORT_FIELD ) {
			return $value;
		}

		return (string) mb_substr( $value, 0, self::MAX_REPORT_FIELD );
	}

	/**
	 * POST a report body without blocking the caller.
	 *
	 * @param string              $endpoint Path beginning with a slash.
	 * @param array<string,mixed> $body     JSON body to send.
	 * @return void
	 */
	private function report( string $endpoint, array $body ): void {
		wp_remote_post(
			$this->base_url . $endpoint,
			array(
				'blocking' => false,
				'timeout'  => self::REPORT_TIMEOUT,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * Fetch and parse the ruleset from the backend, bypassing the cache.
	 *
	 * The single source of the rules request shape — both {@see self::get_rules()}
	 * and {@see self::ping()} go through here.
	 *
	 * @return array<string,mixed>|null Null on any transport/status/parse error.
	 */
	private function fetch_rules(): ?array {
		$response = wp_remote_get(
			$this->rules_url(),
			array(
				'timeout' => self::RULES_TIMEOUT,
			)
		);

		return $this->parse_rules_response( $response );
	}

	/**
	 * Parse and validate a rules HTTP response into a ruleset array.
	 *
	 * @param array|\WP_Error $response Result of {@see wp_remote_get()}.
	 * @return array<string,mixed>|null Null on any transport/status/parse error.
	 */
	private function parse_rules_response( $response ): ?array {
		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		// Defensive: an overflow marker carries no inline rules — treat as empty.
		if ( ! empty( $decoded['overflow'] ) ) {
			return $this->empty_rules();
		}

		if ( ! isset( $decoded['exact'] ) || ! is_array( $decoded['exact'] )
			|| ! isset( $decoded['wildcard'] ) || ! is_array( $decoded['wildcard'] ) ) {
			return null;
		}

		$rules = array(
			'v'        => isset( $decoded['v'] ) ? (int) $decoded['v'] : 2,
			'exact'    => array_values( $decoded['exact'] ),
			'wildcard' => array_values( $decoded['wildcard'] ),
		);

		if ( isset( $decoded['storeUrl'] ) ) {
			$rules['storeUrl'] = (string) $decoded['storeUrl'];
		}
		if ( isset( $decoded['baseUrl'] ) ) {
			$rules['baseUrl'] = (string) $decoded['baseUrl'];
		}

		return $rules;
	}

	/**
	 * Fetch and parse the deleted-entity ids from the backend, bypassing the cache.
	 *
	 * @return array{tracked:bool,products:array<int,int>,categories:array<int,int>}|null
	 *         Null on any transport error, unexpected status, or parse failure.
	 */
	private function fetch_deleted_entities(): ?array {
		$response = wp_remote_get(
			$this->deleted_url(),
			array(
				'timeout' => self::RULES_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		// A 404 is only the definite "store never installed the hosted app"
		// answer when it carries the endpoint's own marker body — a routing
		// 404 (endpoint not deployed yet, URL rewrite, proxy) must stay
		// indeterminate or it would write wrong verdicts for up to a week.
		if ( 404 === $status ) {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( is_array( $decoded ) && self::ERROR_STORE_NOT_TRACKED === ( $decoded['error'] ?? '' ) ) {
				return array(
					'tracked'    => false,
					'products'   => array(),
					'categories' => array(),
				);
			}

			return null;
		}

		if ( 200 !== $status ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded )
			|| ! isset( $decoded['products'] ) || ! is_array( $decoded['products'] )
			|| ! isset( $decoded['categories'] ) || ! is_array( $decoded['categories'] ) ) {
			return null;
		}

		return array(
			'tracked'    => true,
			'products'   => $this->id_list( $decoded['products'] ),
			'categories' => $this->id_list( $decoded['categories'] ),
		);
	}

	/**
	 * Fetch and parse the app-installed flag from the backend, bypassing the cache.
	 *
	 * @return bool|null True/false on a definite answer; null on any transport
	 *                   error, unexpected status (incl. a route-absent 404), or
	 *                   parse failure.
	 */
	private function fetch_app_installed(): ?bool {
		$response = wp_remote_get(
			$this->app_status_url(),
			array(
				'timeout' => self::RULES_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Includes a route-absent 404 before prod promotion: indeterminate,
			// not a definite "false". The backend's normal "unknown store" answer
			// is a 200 with installed:false, handled below.
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! array_key_exists( 'installed', $decoded ) ) {
			return null;
		}

		return (bool) $decoded['installed'];
	}

	/**
	 * Normalize a decoded id list to positive integers.
	 *
	 * @param array<int,mixed> $raw Decoded JSON array.
	 * @return array<int,int>
	 */
	private function id_list( array $raw ): array {
		$ids = array();

		foreach ( $raw as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}
		}

		return $ids;
	}

	/**
	 * The empty/no-rules fallback, shaped like the backend's own empty answer.
	 *
	 * @return array{v:int,exact:array<int,mixed>,wildcard:array<int,mixed>}
	 */
	private function empty_rules(): array {
		return array(
			'v'        => 2,
			'exact'    => array(),
			'wildcard' => array(),
		);
	}

	/**
	 * Full URL of the rules endpoint for this store.
	 *
	 * @return string
	 */
	private function rules_url(): string {
		return $this->base_url . '/api/storefront/rules/' . $this->store_id;
	}

	/**
	 * Full URL of the deleted-entities endpoint for this store.
	 *
	 * @return string
	 */
	private function deleted_url(): string {
		return $this->base_url . '/api/storefront/deleted/' . $this->store_id;
	}

	/**
	 * Full URL of the app-status endpoint for this store.
	 *
	 * @return string
	 */
	private function app_status_url(): string {
		return $this->base_url . '/api/storefront/app-status/' . $this->store_id;
	}

	/**
	 * Transient key for this store's cached ruleset.
	 *
	 * @return string
	 */
	private function rules_transient_key(): string {
		return self::RULES_TRANSIENT_PREFIX . $this->store_id;
	}

	/**
	 * Transient key for this store's cached deleted-entity ids.
	 *
	 * @return string
	 */
	private function deleted_transient_key(): string {
		return self::DELETED_TRANSIENT_PREFIX . $this->store_id;
	}

	/**
	 * Transient key for this store's cached app-installed flag.
	 *
	 * @return string
	 */
	private function app_status_transient_key(): string {
		return self::APP_STATUS_TRANSIENT_PREFIX . $this->store_id;
	}
}
