<?php
/**
 * Discovers the official Ecwid plugin's store id + public token.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the connection details the official Ecwid Shopping Cart plugin already
 * stores, so this companion plugin never asks the merchant to re-enter anything.
 *
 * These option names are an **undocumented contract** with the Ecwid plugin —
 * they are not a stable public API and Ecwid can change them. They are isolated
 * here so a break is a one-file fix (see Risks in the implementation plan):
 *  - `ecwid_store_id`     — the configured store id (plain integer string).
 *  - `ecwid_public_token` — the read-only storefront token written by the OAuth
 *                           exchange (plain, unencrypted). This is the safe token
 *                           the public catalog read needs.
 *
 * The encrypted, write-capable secret (`ecwid_oauth_token`) is deliberately never
 * read — the companion plugin only ever needs read-only access.
 *
 * The store id is read from the raw option rather than the Ecwid plugin's
 * `get_ecwid_store_id()` helper on purpose: that helper falls back to a demo
 * store id when nothing is configured, which would mask an unconfigured install
 * as "connected". The raw option is empty until the merchant actually configures
 * a store, which is exactly the signal we want.
 */
final class EcwidPluginDiscovery {

	/**
	 * Option name holding the Ecwid store id.
	 *
	 * @var string
	 */
	public const STORE_ID_OPTION = 'ecwid_store_id';

	/**
	 * Option name holding the read-only public storefront token.
	 *
	 * @var string
	 */
	public const PUBLIC_TOKEN_OPTION = 'ecwid_public_token';

	/**
	 * Status: the Ecwid Shopping Cart plugin is not active.
	 *
	 * @var string
	 */
	public const STATUS_PLUGIN_MISSING = 'plugin-missing';

	/**
	 * Status: the Ecwid plugin is active but no store is configured.
	 *
	 * @var string
	 */
	public const STATUS_NOT_CONFIGURED = 'not-configured';

	/**
	 * Status: a store id is present but no public token has been issued yet.
	 *
	 * @var string
	 */
	public const STATUS_NO_TOKEN = 'no-token';

	/**
	 * Status: store id and public token are both present.
	 *
	 * @var string
	 */
	public const STATUS_READY = 'ready';

	/**
	 * Whether the Ecwid Shopping Cart plugin is active.
	 *
	 * @var bool
	 */
	private bool $plugin_active;

	/**
	 * Discovered store id (0 when absent).
	 *
	 * @var int
	 */
	private int $store_id;

	/**
	 * Discovered public token ('' when absent).
	 *
	 * @var string
	 */
	private string $public_token;

	/**
	 * Constructor.
	 *
	 * @param bool   $plugin_active Whether the Ecwid plugin is active.
	 * @param int    $store_id      Discovered store id (0 when absent).
	 * @param string $public_token  Discovered public token ('' when absent).
	 */
	public function __construct( bool $plugin_active, int $store_id, string $public_token ) {
		$this->plugin_active = $plugin_active;
		$this->store_id      = $store_id;
		$this->public_token  = $public_token;
	}

	/**
	 * Build an instance from the live WordPress options.
	 *
	 * @return self
	 */
	public static function discover(): self {
		// The Ecwid plugin defines this global helper; its presence is the most
		// reliable runtime signal that the plugin is loaded.
		$plugin_active = function_exists( 'get_ecwid_store_id' ) || defined( 'ECWID_PLUGIN_DIR' );

		$store_id     = (int) get_option( self::STORE_ID_OPTION, 0 );
		$public_token = (string) get_option( self::PUBLIC_TOKEN_OPTION, '' );

		return new self( $plugin_active, $store_id, $public_token );
	}

	/**
	 * Whether the Ecwid Shopping Cart plugin is active.
	 *
	 * @return bool
	 */
	public function plugin_active(): bool {
		return $this->plugin_active;
	}

	/**
	 * Discovered store id (0 when absent).
	 *
	 * @return int
	 */
	public function store_id(): int {
		return $this->store_id;
	}

	/**
	 * Discovered public token ('' when absent).
	 *
	 * @return string
	 */
	public function public_token(): string {
		return $this->public_token;
	}

	/**
	 * Whether a usable store id was discovered.
	 *
	 * @return bool
	 */
	public function has_store_id(): bool {
		return $this->store_id > 0;
	}

	/**
	 * Whether a public token was discovered.
	 *
	 * @return bool
	 */
	public function has_public_token(): bool {
		return '' !== $this->public_token;
	}

	/**
	 * Whether everything needed to connect was discovered.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return self::STATUS_READY === $this->status();
	}

	/**
	 * The discovery status, for display and gating the connect action.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status(): string {
		if ( ! $this->plugin_active ) {
			return self::STATUS_PLUGIN_MISSING;
		}

		if ( ! $this->has_store_id() ) {
			return self::STATUS_NOT_CONFIGURED;
		}

		if ( ! $this->has_public_token() ) {
			return self::STATUS_NO_TOKEN;
		}

		return self::STATUS_READY;
	}
}
