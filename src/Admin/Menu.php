<?php
/**
 * Admin menu registration.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's top-level admin menu and its submenus: 404 Log (the
 * landing page), Redirects, and Settings.
 *
 * Each page's action handling (deletes, filters, redirects) that must run
 * before any output is wired to that page's `load-{hook}` event here, because
 * only the menu registration learns the hook suffixes.
 */
final class Menu {

	/**
	 * Slug of the top-level menu (= the 404 log page).
	 *
	 * @var string
	 */
	public const PARENT_SLUG = LogPage::PAGE_SLUG;

	/**
	 * Required capability for every page.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The 404 log page.
	 *
	 * @var LogPage
	 */
	private LogPage $log_page;

	/**
	 * The redirects page.
	 *
	 * @var RedirectsPage
	 */
	private RedirectsPage $redirects_page;

	/**
	 * The connection settings page.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * Constructor.
	 *
	 * @param LogPage|null       $log_page       404 log page (injectable for tests).
	 * @param RedirectsPage|null $redirects_page Redirects page (injectable for tests).
	 * @param SettingsPage|null  $settings_page  Settings page (injectable for tests).
	 */
	public function __construct(
		?LogPage $log_page = null,
		?RedirectsPage $redirects_page = null,
		?SettingsPage $settings_page = null
	) {
		$this->log_page       = $log_page ?? new LogPage();
		$this->redirects_page = $redirects_page ?? new RedirectsPage();
		$this->settings_page  = $settings_page ?? new SettingsPage();
	}

	/**
	 * Register the menu and every page's request handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );

		$this->log_page->register();
		$this->redirects_page->register();
		$this->settings_page->register();
	}

	/**
	 * Add the top-level menu and its submenu pages.
	 *
	 * @return void
	 */
	public function add_pages(): void {
		add_menu_page(
			__( 'Redirect & 404 Helper for Ecwid', 'ecwid-redirect-404-helper' ),
			__( 'Redirect & 404', 'ecwid-redirect-404-helper' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this->log_page, 'render' ),
			'dashicons-randomize'
		);

		$log_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( '404 Log', 'ecwid-redirect-404-helper' ),
			__( '404 Log', 'ecwid-redirect-404-helper' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this->log_page, 'render' )
		);

		$redirects_hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Redirects', 'ecwid-redirect-404-helper' ),
			__( 'Redirects', 'ecwid-redirect-404-helper' ),
			self::CAPABILITY,
			RedirectsPage::PAGE_SLUG,
			array( $this->redirects_page, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Redirect & 404 Helper for Ecwid', 'ecwid-redirect-404-helper' ),
			__( 'Settings', 'ecwid-redirect-404-helper' ),
			self::CAPABILITY,
			SettingsPage::PAGE_SLUG,
			array( $this->settings_page, 'render_page' )
		);

		// Pre-output action processing (deletes use post/redirect/get, which
		// must run before admin.php prints the page header).
		if ( is_string( $log_hook ) && '' !== $log_hook ) {
			add_action( 'load-' . $log_hook, array( $this->log_page, 'handle_actions' ) );
		}

		if ( is_string( $redirects_hook ) && '' !== $redirects_hook ) {
			add_action( 'load-' . $redirects_hook, array( $this->redirects_page, 'handle_actions' ) );
		}
	}

	/**
	 * The mutation action requested by the current admin request, if any.
	 *
	 * Shared router helper for the list-table pages: the table submits
	 * `action` (top dropdown) or `action2` (bottom); row links carry `action`.
	 * '-1' is the dropdowns' "no selection" value.
	 *
	 * @return string
	 */
	public static function requested_action(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- routing only; each action verifies its own nonce.
		foreach ( array( 'action', 'action2' ) as $param ) {
			$value = isset( $_REQUEST[ $param ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $param ] ) ) : '';
			if ( '' !== $value && '-1' !== $value ) {
				return $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}
}
