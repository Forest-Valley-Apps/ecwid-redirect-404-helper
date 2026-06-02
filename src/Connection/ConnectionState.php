<?php
/**
 * Persisted connect/disconnect state for this plugin.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Stores whether the merchant has opted in to using the discovered Ecwid
 * connection, plus the outcome of the last verification.
 *
 * The store id and public token themselves are **not** stored here — they are
 * always read live from the Ecwid plugin via {@see EcwidPluginDiscovery}. This
 * class only records this plugin's own decision ("the merchant clicked Connect")
 * and the last verification result, so the settings screen has something to show
 * and feature code has a single flag to gate on.
 *
 * "Disconnect" therefore never touches the Ecwid plugin's options — it only
 * flips this opt-in off and lets the caller clear our caches.
 */
final class ConnectionState {

	/**
	 * Option name for the stored state.
	 *
	 * @var string
	 */
	public const OPTION = 'fv_erh_connection';

	/**
	 * Mark the connection as active and record the verified store id.
	 *
	 * @param int $store_id  Verified store id.
	 * @param int $timestamp Unix time of the verification.
	 * @return void
	 */
	public function mark_connected( int $store_id, int $timestamp ): void {
		update_option(
			self::OPTION,
			array(
				'connected'   => true,
				'store_id'    => $store_id,
				'verified_at' => $timestamp,
			),
			false
		);
	}

	/**
	 * Mark the connection as inactive, keeping the last verified store id.
	 *
	 * @return void
	 */
	public function mark_disconnected(): void {
		$state              = $this->get();
		$state['connected'] = false;

		update_option( self::OPTION, $state, false );
	}

	/**
	 * Whether the merchant has an active connection.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return ! empty( $this->get()['connected'] );
	}

	/**
	 * The store id recorded at the last successful connect (0 when none).
	 *
	 * @return int
	 */
	public function store_id(): int {
		return (int) ( $this->get()['store_id'] ?? 0 );
	}

	/**
	 * Unix time of the last successful verification (0 when none).
	 *
	 * @return int
	 */
	public function verified_at(): int {
		return (int) ( $this->get()['verified_at'] ?? 0 );
	}

	/**
	 * Remove the stored state entirely (used on uninstall in a later session).
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Read the raw state array, normalised to an array.
	 *
	 * @return array<string,mixed>
	 */
	private function get(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}
}
