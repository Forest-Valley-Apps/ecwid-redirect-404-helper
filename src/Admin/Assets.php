<?php
/**
 * Admin stylesheet for the plugin's own screens.
 *
 * @package FV\WPEcwidRedirectHelper
 */

declare( strict_types=1 );

namespace FV\WPEcwidRedirectHelper\Admin;

use FV\WPEcwidRedirectHelper\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues the plugin's admin CSS through one handle.
 *
 * All of the plugin's styled chrome — the 404-log badges (type, layer, catalog
 * verdict), the ellipsized cells, the slug-collision panel, the contextual
 * upgrade CTAs, and the upgrade page grid — share this single registered
 * stylesheet, attached via `wp_add_inline_style()` rather than echoed inline
 * (which Plugin Check flags). The handle has no file source; the CSS travels
 * as the inline payload.
 *
 * Loaded only on the plugin's own admin screens (`page=fv-erh-*`). The one
 * styled element that can appear elsewhere — the site-wide slug-collision admin
 * notice — uses core's `.notice` classes only, so it needs no plugin CSS off
 * these screens; nothing else renders the `fv-erh-*` classes outside them.
 */
final class Assets {

	/**
	 * The shared style handle.
	 *
	 * @var string
	 */
	private const HANDLE = 'fv-erh-admin';

	/**
	 * Register the enqueue hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the stylesheet on the plugin's own admin screens.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! self::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		// A source-less handle: the CSS is delivered entirely as the inline
		// payload below, which `wp_add_inline_style()` prints once the (empty)
		// handle is enqueued.
		wp_register_style( self::HANDLE, false, array(), Plugin::VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::css() );
	}

	/**
	 * Whether the current admin screen is one of the plugin's pages.
	 *
	 * Every plugin page slug begins `fv-erh-`, and the hook suffix carries the
	 * slug verbatim (`toplevel_page_fv-erh-404-log`, `..._page_fv-erh-redirects`,
	 * etc.), so a substring test catches them all.
	 *
	 * @param string $hook_suffix The admin page hook suffix.
	 * @return bool
	 */
	private static function is_plugin_screen( string $hook_suffix ): bool {
		return false !== strpos( $hook_suffix, 'fv-erh-' );
	}

	/**
	 * The plugin's admin CSS.
	 *
	 * @return string
	 */
	private static function css(): string {
		return '
			.fv-erh-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; line-height:1.6; color:#fff; }
			/* Badge fills map to Forest Valley brand tokens (Shared-assets-git/styles/shared.css).
			   Literal hexes (not var(--fv-*)) because the brand token stylesheet is not loaded
			   into wp-admin. Status verdicts use the fixed status palette; type/layer use the
			   neutral navy + gray scale, with green reserved for the primary commerce entity. */
			.fv-erh-badge--product { background:#27ae60; } /* --fv-green */
			.fv-erh-badge--category { background:#2c3e50; } /* --fv-navy */
			.fv-erh-badge--wp-page { background:#6c757d; } /* --fv-gray-500 */
			.fv-erh-badge--layer-wp { background:#34495e; } /* --fv-navy-light */
			.fv-erh-badge--layer-storefront { background:#3498db; } /* --fv-info */
			.fv-erh-badge--verdict-in-catalog { background:#27ae60; } /* --fv-green (healthy / still exists) */
			.fv-erh-badge--verdict-deleted { background:#e74c3c; } /* --fv-danger */
			.fv-erh-badge--verdict-never-existed { background:#f39c12; } /* --fv-warning */
			.fv-erh-badge--verdict-not-in-catalog { background:#6c757d; } /* --fv-gray-500 */
			.fv-erh-ellipsis { display:inline-block; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom; }
			.fv-erh-collision-panel { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #dba617; padding:1px 12px 12px; margin:12px 0; }
			.fv-erh-collision-table { max-width:760px; }
			.fv-erh-collision-allclear { color:#646970; }
			.fv-erh-cta { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #27ae60; padding:4px 16px 12px; margin:12px 0; max-width:760px; }
			.fv-erh-cta__tag { display:inline-block; margin-top:12px; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:#fff; background:#27ae60; }
			.fv-erh-cta__title { margin:8px 0 4px; font-size:14px; color:#2c3e50; }
			.fv-erh-cta__body { margin:0 0 12px; color:#2c3e50; }
			.fv-erh-cta__actions { margin:0; }
			.fv-erh-cta__dismiss { margin-left:8px; color:#646970; text-decoration:none; }
			.fv-erh-upgrade__lead { max-width:760px; font-size:14px; color:#2c3e50; }
			.fv-erh-upgrade__note { max-width:760px; color:#646970; }
			.fv-erh-upgrade__grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:16px; max-width:760px; margin-top:16px; }
			.fv-erh-upgrade__card { background:#fff; border:1px solid #c3c4c7; border-top:3px solid #27ae60; padding:4px 16px 16px; }
			.fv-erh-upgrade__card h2 { font-size:15px; color:#2c3e50; }
			.fv-erh-upgrade__card p { color:#2c3e50; }
			/* Always-visible per-row primary action (unbranded): the storefront
			   "Fix in Ecwid" / WP "Create redirect" link, shown inline instead of
			   the hover-only .row-actions tray. */
			.fv-erh-row-cta { margin-top:4px; }
			.fv-erh-row-fix { font-weight:600; text-decoration:none; }
			/* The persistent, branded app button a screen header renders top-right
			   (the 404 Log today). The logo is an <img> that keeps its own colours,
			   so hover uses a soft tint, not a green fill that would swallow the
			   mark. The scoped clear stops the float bleeding past the header divider
			   (this stylesheet loads only on the plugin screens). */
			.fv-erh-app-btn { display:inline-flex; align-items:center; gap:6px; float:right; margin-top:-3px; padding:4px 12px; border:1px solid #27ae60; border-radius:4px; background:#fff; color:#27ae60; font-weight:600; line-height:1.8; text-decoration:none; }
			.fv-erh-app-btn:hover, .fv-erh-app-btn:focus { background:#f0faf4; border-color:#1e8e4f; color:#1e8e4f; }
			.fv-erh-app-btn__logo { display:block; width:20px; height:auto; }
			.wp-header-end { clear:both; }
			/* WP-vs-storefront layer banner (Redirects screen): a branded, always-on
			   orientation banner — app logo + the honest boundary + a deep-link to
			   manage storefront redirects. */
			.fv-erh-layer-banner { display:flex; align-items:center; gap:14px; max-width:760px; margin:12px 0; padding:12px 16px; background:#fff; border:1px solid #c3c4c7; border-left:4px solid #27ae60; }
			.fv-erh-layer-banner__logo { flex:0 0 auto; width:40px; height:auto; }
			.fv-erh-layer-banner__text { margin:0; color:#2c3e50; }
			/* Small inline app logo for the lighter "logo on the guidance" note
			   (Settings, ready-but-not-connected) — no banner box, no CTA. */
			.fv-erh-inline-logo { width:20px; height:auto; vertical-align:middle; margin-right:6px; }
		';
	}
}
