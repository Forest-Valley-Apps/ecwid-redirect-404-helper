<?php
/**
 * Ecwid storefront catalog client.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Reads product/category existence from the Ecwid REST catalog using the
 * store's **public storefront token**.
 *
 * The public token is the one the official Ecwid Shopping Cart plugin already
 * embeds client-side, so it is recoverable from the page with no merchant
 * action (token discovery itself is Session 3's job). The public token grants
 * read-only catalog access — this client never touches admin/write endpoints.
 *
 * Existence is reported as a tri-state so callers can distinguish a definite
 * answer from a transient failure:
 *  - {@see self::EXISTS}    — the entity is live (HTTP 200).
 *  - {@see self::NOT_FOUND} — the entity is absent (HTTP 404). Whether that
 *                             means "deleted" or "never existed" is decided by
 *                             the deleted-vs-typo logic in a later session.
 *  - {@see self::UNKNOWN}   — transport error or unexpected status; caller
 *                             should retry later rather than treat as absent.
 *
 * These are admin-side, batched lookups (never on a storefront page render),
 * so a normal blocking request with a modest timeout is appropriate.
 */
final class EcwidCatalogClient {

	/**
	 * Default Ecwid REST API base (no store segment, no trailing slash).
	 *
	 * @var string
	 */
	public const DEFAULT_BASE_URL = 'https://app.ecwid.com/api/v3';

	/**
	 * Existence status: the entity is live.
	 *
	 * @var string
	 */
	public const EXISTS = 'exists';

	/**
	 * Existence status: the entity is absent (404).
	 *
	 * @var string
	 */
	public const NOT_FOUND = 'not-found';

	/**
	 * Existence status: indeterminate (transport error / unexpected status).
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Credential check: the token authenticates against this store.
	 *
	 * @var string
	 */
	public const AUTH_OK = 'auth-ok';

	/**
	 * Credential check: the token was rejected (HTTP 401/403).
	 *
	 * @var string
	 */
	public const AUTH_FAILED = 'auth-failed';

	/**
	 * Credential check: indeterminate (transport error / unexpected status).
	 *
	 * @var string
	 */
	public const AUTH_UNKNOWN = 'auth-unknown';

	/**
	 * Timeout, in seconds, for a catalog lookup.
	 *
	 * @var int
	 */
	private const TIMEOUT = 5;

	/**
	 * Maximum product ids per batched existence request.
	 *
	 * Ecwid's products search returns at most 100 items per page (its `limit`
	 * maximum and default), so a longer id list must be split — an overflowing
	 * page would silently misread the truncated tail as absent.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 100;

	/**
	 * Ecwid store id.
	 *
	 * @var int
	 */
	private int $store_id;

	/**
	 * Public storefront token.
	 *
	 * @var string
	 */
	private string $public_token;

	/**
	 * Ecwid REST API base URL (no trailing slash).
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param int    $store_id     Ecwid store id.
	 * @param string $public_token Public storefront token.
	 * @param string $base_url     Ecwid REST base URL. Defaults to the public API.
	 */
	public function __construct( int $store_id, string $public_token, string $base_url = self::DEFAULT_BASE_URL ) {
		$this->store_id     = $store_id;
		$this->public_token = $public_token;
		$this->base_url     = untrailingslashit( $base_url );
	}

	/**
	 * Whether a product currently exists in the catalog.
	 *
	 * @param int $product_id Ecwid product id.
	 * @return string One of the EXISTS / NOT_FOUND / UNKNOWN constants.
	 */
	public function product_exists( int $product_id ): string {
		return $this->check( 'products', $product_id );
	}

	/**
	 * Whether a category currently exists in the catalog.
	 *
	 * @param int $category_id Ecwid category id.
	 * @return string One of the EXISTS / NOT_FOUND / UNKNOWN constants.
	 */
	public function category_exists( int $category_id ): string {
		return $this->check( 'categories', $category_id );
	}

	/**
	 * Whether each of a set of products currently exists in the catalog.
	 *
	 * One products-search request per {@see self::BATCH_LIMIT} ids — Ecwid's
	 * search accepts a comma-separated `productId` list (every other search
	 * param is then ignored), so a whole verdict batch costs one HTTP
	 * round-trip instead of one per product. Categories have no equivalent
	 * id-list search (only parent-scoped params), so there is deliberately no
	 * category twin of this method.
	 *
	 * The answer covers every requested id: the search 200s and simply omits
	 * absent products from `items`, so a missing id is a definite NOT_FOUND —
	 * with the same public-token caveat as the single lookup (a disabled
	 * product reads as absent). An indeterminate request fails the whole batch
	 * (null) rather than guessing, mirroring the single lookup's UNKNOWN.
	 *
	 * @param array<int,int> $product_ids Ecwid product ids.
	 * @return array<int,string>|null Product id => EXISTS / NOT_FOUND for every
	 *                                requested id, or null when indeterminate
	 *                                (transport error, unexpected status,
	 *                                unparseable body, or no token yet).
	 */
	public function products_exist( array $product_ids ): ?array {
		if ( array() === $product_ids ) {
			return array();
		}

		if ( '' === $this->public_token ) {
			// No token discovered yet (Session 3's job) — can't authenticate.
			return null;
		}

		$statuses = array();

		foreach ( array_chunk( $product_ids, self::BATCH_LIMIT ) as $chunk ) {
			$found = $this->fetch_existing_product_ids( $chunk );
			if ( null === $found ) {
				return null;
			}

			foreach ( $chunk as $id ) {
				$statuses[ (int) $id ] = in_array( (int) $id, $found, true )
					? self::EXISTS
					: self::NOT_FOUND;
			}
		}

		return $statuses;
	}

	/**
	 * Verify that the configured public token authenticates against this store.
	 *
	 * Used by the connect flow as a lightweight "catalog ping". Lists a single
	 * product (`responseFields=count` keeps the payload tiny) and inspects the
	 * status: a `200` proves the token + store id are valid; a `401`/`403`
	 * proves the token is rejected; anything else is indeterminate.
	 *
	 * @return string One of the AUTH_OK / AUTH_FAILED / AUTH_UNKNOWN constants.
	 */
	public function verify_credentials(): string {
		if ( '' === $this->public_token ) {
			return self::AUTH_FAILED;
		}

		$status = $this->request_status( $this->ping_url() );

		if ( null === $status ) {
			return self::AUTH_UNKNOWN;
		}

		if ( 200 === $status ) {
			return self::AUTH_OK;
		}

		if ( 401 === $status || 403 === $status ) {
			return self::AUTH_FAILED;
		}

		return self::AUTH_UNKNOWN;
	}

	/**
	 * Look up a catalog entity by id and map the HTTP result to a status.
	 *
	 * @param string $collection Catalog collection ('products' or 'categories').
	 * @param int    $id         Entity id.
	 * @return string One of the EXISTS / NOT_FOUND / UNKNOWN constants.
	 */
	private function check( string $collection, int $id ): string {
		if ( '' === $this->public_token ) {
			// No token discovered yet (Session 3's job) — can't authenticate.
			return self::UNKNOWN;
		}

		$status = $this->request_status( $this->entity_url( $collection, $id ) );

		if ( null === $status ) {
			return self::UNKNOWN;
		}

		if ( 200 === $status ) {
			return self::EXISTS;
		}

		if ( 404 === $status ) {
			return self::NOT_FOUND;
		}

		return self::UNKNOWN;
	}

	/**
	 * Fetch the subset of a product-id chunk that exists in the catalog.
	 *
	 * @param array<int,int> $ids Product ids (at most BATCH_LIMIT of them).
	 * @return array<int,int>|null The ids present in the catalog, or null when
	 *                             the request was indeterminate.
	 */
	private function fetch_existing_product_ids( array $ids ): ?array {
		$response = $this->request( $this->products_batch_url( $ids ) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) {
			return null;
		}

		$found = array();

		foreach ( $decoded['items'] as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$found[] = (int) $item['id'];
			}
		}

		return $found;
	}

	/**
	 * Issue an authenticated catalog GET and return the HTTP status code.
	 *
	 * For callers that only need the status; they map it to their own
	 * tri-state constants.
	 *
	 * @param string $url Full request URL.
	 * @return int|null The HTTP status code, or null on a transport error.
	 */
	private function request_status( string $url ): ?int {
		$response = $this->request( $url );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Issue an authenticated catalog GET and return the raw response.
	 *
	 * The single source of the catalog request shape (timeout + auth header) —
	 * every catalog call goes through here.
	 *
	 * @param string $url Full request URL.
	 * @return array|\WP_Error Result of {@see wp_remote_get()}.
	 */
	private function request( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					// Ecwid discontinued query-param tokens (2025-03); Bearer only.
					'Authorization' => 'Bearer ' . $this->public_token,
				),
			)
		);
	}

	/**
	 * Full URL for a catalog entity lookup.
	 *
	 * The token travels in the Authorization header (see
	 * {@see self::request_status()}), not the query string. Requests only the
	 * `id` field to keep the response small.
	 *
	 * @param string $collection Catalog collection ('products' or 'categories').
	 * @param int    $id         Entity id.
	 * @return string
	 */
	private function entity_url( string $collection, int $id ): string {
		return sprintf(
			'%s/%d/%s/%d?responseFields=id',
			$this->base_url,
			$this->store_id,
			$collection,
			$id
		);
	}

	/**
	 * Full URL for a batched product-existence lookup.
	 *
	 * Requests only each item's `id` field, so the response stays small no
	 * matter how many products the chunk names.
	 *
	 * @param array<int,int> $ids Product ids.
	 * @return string
	 */
	private function products_batch_url( array $ids ): string {
		return sprintf(
			'%s/%d/products?productId=%s&responseFields=items(id)',
			$this->base_url,
			$this->store_id,
			implode( ',', array_map( 'intval', $ids ) )
		);
	}

	/**
	 * URL for the lightweight credential-check request.
	 *
	 * Lists products capped at one row and asks only for the result `count`, so
	 * the response stays minimal regardless of catalog size.
	 *
	 * @return string
	 */
	private function ping_url(): string {
		return sprintf(
			'%s/%d/products?limit=1&responseFields=count',
			$this->base_url,
			$this->store_id
		);
	}
}
