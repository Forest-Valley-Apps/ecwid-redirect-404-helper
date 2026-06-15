# WordPress.org assets

WP.org serves a plugin's banner, icon, and screenshots from the SVN `assets/`
directory, **not** from the plugin zip. This folder is that directory's source
of truth: at submission (implementation-plan S9) its contents are copied into
SVN `assets/` (the layout the `10up/action-wordpress-plugin-deploy` action
expects). It is excluded from the distributable zip via `.distignore`.

> These must be the **plugin's own** screenshots. The paid-app marketing shots in
> `Shared-assets-git/apps-assets/seo-redirect-logo/screenshot-*.png` are NOT
> ours — that directory is rsynced from the shared Drive library by
> `scripts/sync-assets.sh`, so anything dropped there is overwritten on the next
> sync. Shipping those would misrepresent the plugin on WP.org. Keep the plugin
> screenshots here instead.

## Files expected here

| File | WP.org slot | Size | Status |
|------|-------------|------|--------|
| `screenshot-1.png` | Screenshot 1 — 404 Log | ~1200×750 | **to capture** |
| `screenshot-2.png` | Screenshot 2 — Catalog verdicts | ~1200×750 | **to capture** |
| `screenshot-3.png` | Screenshot 3 — Redirects | ~1200×750 | **to capture** |
| `banner-1544x500.png` / `banner-772x250.png` | Header banner | exact | re-crop from `Header_icon 1024x538.png` (S9) |
| `icon-256x256.png` / `icon-128x128.png` | Plugin icon | exact | downscale from `logo_Icon.png` (S9) |

`screenshot-N.png` maps **by number** to the Nth entry of the `== Screenshots ==`
list in `readme.txt`. Keep the two in lockstep.

## Capturing the three screenshots

The demo data is staged on the NAS WordPress (`wp.local`, login `wpadmin`). If the
log has been wiped, re-seed it first: `bash scripts/seed-demo-404s.sh` then finish
the wp-admin steps it prints. Then capture each screen at ~1200px wide (admin menu
included is fine — it reads as a real plugin in wp-admin):

1. **`screenshot-1.png` — the 404 Log.**
   `…/wp-admin/admin.php?page=fv-erh-404-log`
   Shows every broken URL with the **Type**, **Fix at** (WordPress vs Storefront),
   and **Catalog** verdict columns. The staged set has WP-layer rows
   (*Create redirect*, some already *Redirected*) and storefront rows
   (*Fix in app ↗*) — the Scout → Fixer split on one screen.

2. **`screenshot-2.png` — catalog verdicts.**
   Same page, **Type filter = "Ecwid product"**, then *Filter*:
   `…&classification=product` — the three storefront rows with verdicts
   **Deleted / Deleted / Never existed**, i.e. deleted products told apart from a
   typo. This is the Ecwid-aware differentiator.

3. **`screenshot-3.png` — the Redirects screen.**
   `…/wp-admin/admin.php?page=fv-erh-redirects`
   The three WordPress-layer 301s (including a `/products/* → /shop/*` wildcard)
   with hit counts, plus the honest "bulk/migration lives in the app" CTA.

### Getting real "Deleted" verdicts on wp.local

The verdict checker calls the backend's deletion ledger. wp.local is pointed at
**staging**, whose ledger is empty, so every absent product resolves to
*Never existed*. Production (the shipped default) has the genuine ledger. To stage
a real *Deleted* verdict on wp.local:

1. Plugins → deactivate **"FV ERH — staging backend override (wp.local only)"**
   (this falls the helper back to the prod default).
2. Clear the stale ledger cache (transient `fv_erh_deleted_130416012`) — it is
   keyed by store id, 1 h TTL, with no admin "clear" button; deactivating the
   override does not clear it. Simplest: wait out the hour, or temporarily edit
   the override plugin to `delete_transient( 'fv_erh_deleted_130416012' );`.
3. Delete + re-seed the storefront rows, then **404 Log → Check catalog**: the
   prod-deleted ids (`823808971`, `823816367`, …) now resolve to **Deleted**.
4. **Restore** the staging override afterward (it is the dev default).

The prod-deleted product ids are returned by
`GET https://redirect-manager-prod.up.railway.app/api/storefront/deleted/130416012`.
