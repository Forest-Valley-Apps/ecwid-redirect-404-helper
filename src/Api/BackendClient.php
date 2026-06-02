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
 * Talks to the three **public** read/report endpoints only — never the
 * privileged admin write API (those endpoints sit behind Ecwid-iframe auth):
 *  - `GET  /api/storefront/rules/:storeId` — the resolved redirect ruleset.
 *  - `POST /api/storefront/404`            — report a captured 404.
 *  - `POST /api/storefront/hit`            — report a redirect hit.
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
	 * Transient key for this store's cached ruleset.
	 *
	 * @return string
	 */
	private function rules_transient_key(): string {
		return self::RULES_TRANSIENT_PREFIX . $this->store_id;
	}
}
