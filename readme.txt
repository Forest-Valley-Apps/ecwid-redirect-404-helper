=== Redirect & 404 Helper for Ecwid ===
Contributors: alexfv
Tags: ecwid, 404, redirect, broken links, seo
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The only WordPress 404/redirect tool that understands Ecwid's embedded store URLs.

== Description ==

Redirect & 404 Helper for Ecwid is a companion plugin for stores running the
official Ecwid Shopping Cart plugin on WordPress. Because Ecwid products are
JavaScript-rendered views on a single host page, generic redirect plugins cannot
classify or redirect them. This plugin is Ecwid-aware.

This is an early scaffold. Features arrive per the implementation plan:

* Ecwid-aware 404 logging and classification (product / category / WordPress page)
* "Deleted vs. typo" detection against the live Ecwid catalog
* False-404 collision warner for WordPress slugs that collide with Ecwid's `-(p|c)123` URL pattern
* Manual 301 redirects and CSV export of the 404 log

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ecwid-redirect-404-helper`, or install it from the Plugins screen.
2. Activate it through the **Plugins** screen in WordPress.
3. Ensure the official Ecwid Shopping Cart plugin is installed and configured.

== Frequently Asked Questions ==

= Does this work without the Ecwid plugin? =

The Ecwid-aware features require the official Ecwid Shopping Cart plugin. Generic
404 logging works regardless.

== Changelog ==

= 0.1.0 =
* Initial plugin scaffold (tooling and bootstrap only; no user-facing features yet).
