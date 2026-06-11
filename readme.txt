=== Redirect & 404 Helper for Ecwid ===
Contributors: alexfv
Tags: ecwid, 404, redirect, broken links, seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The only WordPress 404/redirect tool that understands Ecwid's embedded store URLs.

== Description ==

Redirect & 404 Helper for Ecwid finds, explains, and helps you fix 404 errors on
WordPress sites running an embedded Ecwid store.

When your site embeds an Ecwid store, broken URLs come in two flavors that look
identical to a visitor but need completely different fixes:

* **WordPress 404s** — an old blog post, a renamed page, a mistyped link.
* **Ecwid store 404s** — a product or category URL inside the embedded store
  (`/store/some-product-p123456789`) that no longer resolves, because the item was
  deleted, renamed, or the link was wrong from the start.

Ecwid store pages are rendered by JavaScript inside a single WordPress page, so a
generic redirect plugin (Redirection, Yoast, Rank Math) only ever sees the first
kind — it cannot tell a dead product from a typo, or redirect store sub-routes at
all. This plugin is Ecwid-aware.

= What it does (free) =

* **Ecwid-aware 404 logging.** Every 404 is captured server-side and classified —
  WordPress page, Ecwid product, Ecwid category, or store sub-route — with hit
  counts, last referrer, and last-seen time.
* **"Deleted vs. typo" catalog verdicts.** For Ecwid product/category 404s, the
  plugin checks the live Ecwid catalog and tells you whether the item is live
  (a broken link), was deleted, or never existed.
* **False-404 collision warner.** Warns when a WordPress page slug collides with
  your store's URL space, which produces confusing "false 404s".
* **Manual 301 redirects.** Create real WordPress-layer 301s, served before
  WordPress takes its own "did you mean" guess.
* **CSV export.** Download the 404 log for offline analysis or to prepare a bulk
  mapping.

= WordPress layer vs. storefront layer =

Your site has two URL layers, and each can only be fixed at its own layer. This
plugin handles the **WordPress layer**: pages, posts, the store page itself, and
manual 301s. Routes rendered *inside* the embedded store by Ecwid's JavaScript
(products, categories, cart) are the **storefront layer** — no WordPress plugin
can redirect those, because WordPress already answered the request with HTTP 200
before Ecwid's script runs. This plugin **detects and classifies** those
storefront 404s and is honest about which layer fixes each one.

= Free vs. paid =

The line is **effort, not capability.** The free plugin is genuinely complete for
hand-fixing a small store. When a job is better done automatically or in bulk —
migration imports, bulk URL mapping, automatic deleted-product redirects, and the
storefront-layer redirects a WordPress plugin fundamentally cannot perform — the
plugin points you to its paid companion, the **Redirect & 404 Manager** app, which
runs inside your Ecwid admin. The prompts are dismissible and stay dismissed; the
plugin never changes your store.

== Installation ==

1. In wp-admin, go to **Plugins → Add New** and search for "Redirect & 404 Helper
   for Ecwid", or upload the plugin zip via **Upload Plugin**.
2. Click **Install Now**, then **Activate**.
3. Make sure the official **Ecwid Shopping Cart** plugin is installed and connected
   to your store. The helper discovers your Store ID and public token from it
   automatically — no passwords or API keys are entered into this plugin.

From the moment it is active, every real 404 on the site is captured and
classified in the background. Open the **404 Log** to see what visitors are
hitting.

== Frequently Asked Questions ==

= Does this work without the Ecwid plugin? =

Plain WordPress 404 logging and manual 301 redirects work regardless. The
Ecwid-aware features (catalog verdicts, collision warnings) require the official
Ecwid Shopping Cart plugin, which the helper reads its store connection from.

= Do I have to enter any API keys or passwords? =

No. The plugin discovers your Store ID and public storefront token from the
official Ecwid plugin's own settings. Nothing is entered into this plugin.

= Why don't store product 404s show up in the log? =

Ecwid product/category views are rendered by JavaScript after WordPress has
already answered the request with HTTP 200, so they never reach WordPress's 404
handling as a redirectable request. The plugin captures what arrives at the
WordPress layer and classifies recognizable store URLs, but the storefront layer
is where in-store routes are actually fixed.

= What does the "Deleted" verdict mean, and why do some deleted products show "Never existed"? =

"Deleted" means a deletion is on record for that item. Deletion history begins
when the paid Redirect & 404 Manager app is installed on your store, so items
deleted before that — or in stores without the app — honestly show as "Never
existed" rather than guessing.

= A redirect I created isn't firing. =

Confirm it is enabled, check that the source path matches exactly what the browser
requests (compare with the path in the 404 Log), and purge any page cache that may
be serving a stale 404. If the destination itself redirects again, point the
source directly at the final URL.

= Does the plugin track me or send data anywhere? =

WordPress-page 404s are recorded only in your own site's database and are never
sent anywhere. Only **Ecwid store** 404s are reported to the companion backend —
and only after you click **Connect**, which is the explicit opt-in (Disconnect
stops it). Those reports contain just the broken store path and its referrer,
scoped by your public store ID. The plugin uses only public read/report endpoints
and never writes to your store.

== Privacy and external services ==

This plugin connects to two external services. Neither is contacted until you click **Connect**
on the plugin's settings page — that is the explicit opt-in, and **Disconnect** stops both. No
analytics or visitor tracking is performed by either connection.

**1. The hosted Redirect & 404 Manager backend** (https://redirect-manager-prod.up.railway.app),
operated by Forest Valley as the companion service to the paid Ecwid app.

* **When** — only while connected.
* **What** — the plugin reports Ecwid store 404s (the broken store path and its referrer),
  scoped by your public Ecwid store ID, and reads your store's deletion history and paid-app
  install status. WordPress-page 404s are stored only in your own site's database and are never
  transmitted. Nothing beyond the requested URL, its referrer, and the store ID is sent.
* **Why** — to classify whether an Ecwid 404 points to a deleted product, a live item, or a typo.
* **Service page:** https://apps.fv.dev/redirect-404-manager/ — **Privacy policy:**
  https://apps.fv.dev/privacy/

**2. The Ecwid API** (https://app.ecwid.com/api/v3), operated by Ecwid by Lightspeed — the
platform your store already runs on.

* **When** — only while connected, when the plugin checks catalog verdicts: from the 404 Log's
  "Check catalog" button and from the hourly background task.
* **What** — read-only existence lookups for the product and category IDs found in your own 404
  log, authenticated with the store's public storefront token (discovered from the official
  Ecwid plugin; this plugin never reads or sends the secret token). No visitor data is sent.
* **Why** — to tell whether a 404'd product or category is still live in your catalog.
* **Privacy policy:** https://www.lightspeedhq.com/legal/privacy-policy/

The plugin uses only public read/report endpoints, never the write API, and never changes your
store.

== Screenshots ==

1. The 404 Log — every broken URL, classified by type with a live catalog verdict.
2. Catalog verdicts distinguish deleted products from typos and broken links.
3. The Redirects screen — manual WordPress-layer 301s with hit counts.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Ecwid-aware 404 logging and classification (WordPress page / Ecwid product /
  Ecwid category / store sub-route).
* "Deleted vs. typo" catalog verdicts against the live Ecwid catalog.
* False-404 slug-collision warner.
* Manual WordPress-layer 301 redirects with hit tracking.
* CSV export of the 404 log.
* Automatic store connection via the official Ecwid Shopping Cart plugin.

== Upgrade Notice ==

= 1.0.0 =
First public release of Redirect & 404 Helper for Ecwid.
