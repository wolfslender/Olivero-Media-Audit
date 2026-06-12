=== OliveroDev Media Audit – Media Library Cleaner & Optimizer ===
Contributors: oliverodev, alexis-olivero
Tags: media cleaner, media library, unused media, media cleanup, media optimizer
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.4.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and delete unused media files in your WordPress media library. Smart scanning, safe cleanup, and storage optimization — completely free.

== Description ==

**Is your WordPress media library full of files nobody is using?** Over time, images, documents, and other uploads accumulate without ever being referenced in posts, pages, or theme settings. OliveroDev Media Audit scans every corner of your site and tells you exactly which files are safe to delete — so you can recover disk space without breaking anything.

**Smart detection that actually works.** Most media cleaner plugins miss references stored in page builders, widgets, or serialized data. OliveroDev Media Audit checks post content, post meta, term meta, user meta, theme mods, widget options, and more — all in a single optimized scan — to reduce false positives and keep your site intact.

= What you get for FREE =

* **Media library scan** — Identify unused images, documents, videos, and audio files in your WordPress uploads.
* **Dashboard overview** — See used vs. unused file counts and the total storage you can recover at a glance.
* **Safe, controlled cleanup** — Review every file before deleting. No surprises, no bulk wipes you didn't authorize.
* **Batch scanning** — Scans run in configurable batches to avoid server timeouts, even on large libraries.
* **Automatic background scans** — Schedule recurring WP-Cron scans (hourly, daily, weekly) to keep your library clean over time.
* **File type filtering** — Focus on images, documents, videos, audio, or archives depending on what you need to clean.
* **Image format support** — JPEG, PNG, GIF, WebP, and SVG all covered.
* **Zero configuration required** — Install, run a scan, and start recovering space.

= How it works =

1. Go to **Tools → OliveroDev Media Audit** in your WordPress admin.
2. Click **Start New Scan**. The plugin checks every attachment in your library against all content on your site.
3. Review the results. Files marked **Unused** are not referenced anywhere on your site.
4. Delete unused files individually — or switch to the **Unused Files** tab to work through the full cleanup list.

= What it checks =

The detection engine searches for media references in:

* Posts, pages, and custom post types (including draft and private)
* Post meta and serialized data (including Gutenberg block attributes)
* Theme options and customizer settings (logo, header image, background image)
* Widget data and sidebar configurations
* Term meta and user meta
* WordPress options table
* Featured images (post thumbnails)
* Site icon setting
* All registered image sizes (thumbnails, medium, large)

= PRO: Go deeper =

The **PRO add-on** (available separately) unlocks integrations with the most popular WordPress tools:

* **Deep Detection** for Advanced Custom Fields (ACF), Divi Builder, Elementor, WooCommerce product images, and more.
* **Storage Analysis** with visual charts — see MB/GB breakdown by file type, identify your heaviest content.
* **Intelligent Trash System** — move files to trash before permanent deletion for an extra safety layer.
* **One-click Bulk Cleanup** — delete all unused files in a single operation.
* **Extended file type support** — full coverage for documents (PDF, DOC, XLS), video, audio, and archive files.
* **Advanced automation** — fine-grained scheduling and cleanup triggers.

[Get PRO →](https://checkout.freemius.com/product/23055/)

= Who is this plugin for? =

* **Site owners** who want to free up hosting disk space without hiring a developer.
* **Agencies** managing multiple WordPress sites that accumulate unused uploads over time.
* **Developers** who need a reliable way to audit a client's media library before a migration.
* **WooCommerce stores** where product images are frequently updated or deleted from the catalog.
* **Bloggers and content creators** who upload many images and want to keep their library organized.

= Privacy =

This plugin does not collect any personal data, make external HTTP requests, or send usage statistics anywhere. All scanning happens locally on your server.

== Installation ==

**From the WordPress plugin directory:**

1. Search for "OliveroDev Media Audit" in **Plugins → Add New**.
2. Click **Install Now**, then **Activate**.
3. Open **Tools → OliveroDev Media Audit** and run your first scan.

**Manual installation:**

1. Download the plugin ZIP from WordPress.org.
2. Upload the contents to `/wp-content/plugins/oliverodev-media-audit/`.
3. Activate from **Plugins** in your WordPress admin.
4. Open **Tools → OliveroDev Media Audit** and run your first scan.

== Frequently Asked Questions ==

= Is it safe to use on a live production site? =

Yes. The plugin reads content to determine whether a file is in use; it never deletes anything automatically. You review the results first and choose which files to remove one at a time.

= Will it detect media used in Elementor or Divi? =

The free version searches all post content, including raw Elementor and Divi data stored in post meta. The PRO version adds dedicated deep-detection integrations for these builders to catch edge cases in their proprietary storage formats.

= Can it detect images used in ACF (Advanced Custom Fields)? =

The free version scans post meta values and will find most ACF image references stored as attachment IDs or URLs. The PRO version includes a dedicated ACF integration for complete coverage of repeaters and flexible content fields.

= How do I find unused images in WordPress? =

Install the plugin, go to **Tools → OliveroDev Media Audit**, click **Start New Scan**, and open the **Unused Files** tab when the scan finishes. Every file listed there was not found in any post, page, or site setting.

= How long does a scan take? =

It depends on your library size. The scan runs in batches (configurable from 1 to 200 files per batch) directly in your browser via AJAX, so it won't time out even on large libraries with thousands of files.

= What happens when I delete a file? =

The plugin calls WordPress's native `wp_delete_attachment()` function, which removes the attachment post, all registered image sizes, and the original file from your server. This is the same process WordPress uses in the media library.

= Can I recover a deleted file? =

No. Deletion is permanent. If you need a safety net before bulk cleanup, the PRO version includes an intelligent Trash System that lets you move files to a staging area before committing to deletion.

= Does it work with WooCommerce? =

The free version scans product descriptions, product gallery meta, and variation image meta. The PRO version adds dedicated WooCommerce detection to cover featured product images and gallery references more reliably.

= Will deleting unused media affect my SEO? =

Only if those files were indexed by search engines and linked from external sites. For files that are truly unused (not referenced in any post or page on your site), there is no SEO impact. If you are unsure, review each file individually before deleting.

= Where does the plugin appear in the WordPress admin? =

Go to **Tools → OliveroDev Media Audit**.

= Does it support multisite? =

The plugin is designed for single-site installations. Multisite compatibility is on the roadmap.

= Can I schedule automatic scans? =

Yes. Go to **Tools → OliveroDev Media Audit → Settings** and choose a scan frequency: hourly, twice daily, daily, or weekly. The scan runs in the background via WP-Cron.

= What file types does the free version support? =

Images: JPEG, PNG, GIF, WebP, SVG. The PRO version extends this to PDF, DOC, DOCX, XLS, XLSX, MP4, MP3, ZIP, RAR, and more.

= I found a false positive — a file shown as "unused" that I know is in use. What should I do? =

Do not delete it. The free version covers the most common storage locations. If you are using a plugin or theme with a custom database structure, the file may be referenced in a table the free version does not scan. The PRO version covers the most popular third-party tools. You can also filter the detection using the `oliverodev_media_audit_is_media_used` WordPress filter hook.

== Screenshots ==

1. Dashboard with total library size, files in use, and storage to recover.
2. Unused Files tab — full list of unreferenced media ready for cleanup.
3. Media Library tab — full library view with used/unused status and file details.
4. Settings — configure batch size, scan frequency, and file type filters.

== Changelog ==

= 3.4.7 =
* Fix: Elementor 4.x Grid background images (Spacer widget inside Grid container) are now detected during scans — the scanner explicitly processes all `_elementor_data` rows for atomic $$type format IDs, including rows without upload URLs that step 7's URL filter previously skipped.

= 3.4.6 =
* Fix: Broaden Elementor 4.x $$type detection to match ANY `$$type` object (image, background, grid, etc.) with a numeric `"value"`, not only `"image-attachment-id"`. Previously missed backgrounds on Grid → Spacer and similar nested element structures.

= 3.4.5 =
* Fix: Elementor 4.x atomic format detection now covers all keys, not just `"id"`. Background images stored under `"background_image"`, `"image"`, or any other key using `$$type` objects are now detected.
* Fix: "Where is it used?" now searches `_elementor_data` and `_elementor_css` postmeta for both classic and atomic ID patterns + URL, fixing the "No specific location found" message for Elementor content.

= 3.4.4 =
* Fix: Elementor 4.x compatibility — the scanner now detects attachment IDs stored in the new atomic JSON format (`$$type` descriptors). Elementor 4.x stores `"id": {"$$type":"image-attachment-id","value":N}` instead of the classic `"id":N`, and the previous regex missed it entirely. Background images on Containers/Sections now show as "used" on Elementor 4.x sites.

= 3.4.3 =
* Fix: Protocol-agnostic URL matching — the scanner now checks both http and https variants when scanning postmeta, options, usermeta, and termmeta. Fixes false positives where page builders (Elementor, etc.) stored URLs with a different scheme than the current site URL.
* Fix: Elementor CSS transient is now cleared on `save_post`, so newly generated CSS files are picked up on the next scan instead of being missed due to stale cache.

= 3.4.2 =
* Security: Hardened SQLi prevention — table and column names are validated against a whitelist before being used in database queries.
* Security: Added `realpath()` resolution to prevent symlink-based path traversal in file deletion routines.
* Security: File size helper now validates the path is within the uploads directory.
* Security: Escaped `wp_get_attachment_image()` output to prevent stored XSS via image alt text.
* Security: Replaced `FILTER_UNSAFE_RAW` with direct `sanitize_key()` calls for all input variables.
* Security: Added `index.php` to logs directory for directory listing hardening.
* Security: Elementor CSS file reading now validates files are within the expected directory.

= 3.4.1 =
* Fix: "Start New Scan" / "Resume" button icon now stays white at rest (was inheriting an inconsistent blue/green from the admin color scheme) and turns purple while the scan animation is running.

= 3.4.0 =
* New: Slider safety net — the scanner now also checks Smart Slider 3 and LayerSlider tables (in addition to the existing Slider Revolution check). Images used inside these sliders are detected as "in use" and protected from accidental deletion, even when only referenced via shortcode.

= 3.3.11 =
* Rewrite: Inverted-Index scan engine — builds a complete set of in-use attachment IDs once per scan (scanning every content source in a single DB pass), then per-file checks are O(1) array lookups. Eliminates the N×M LIKE queries that caused false negatives and timeouts.
* Fix: "everything shows as unused" — root cause was unreliable LIKE-query approach. The new engine uses PHP regex on fetched content, exact ID queries, and a URL→ID reverse map, eliminating LIKE escaping bugs entirely.
* Detection now covers: post_content (regex), postmeta, wp_options, wp_usermeta, wp_termmeta, Elementor CSS files (disk), Elementor compressed data, Slider Revolution, featured images, WooCommerce galleries, ACF ID fields, shortcode gallery IDs, site icon, custom logo.

* New: Real-time scan counter — dashboard shows "X unused found" updating live while scan runs, eliminating the perception that the plugin found nothing during long scans.
* Fix: Sort by Size on Unused tab no longer breaks the unused filter — the OR clause was overriding the unused-only condition.
* Fix: Sort by Size on Library tab now includes all files regardless of scan status.

= 3.3.8 =
* Fix: Library tab no longer shows blank page on fresh installs — rows without scan data show a "Not scanned" badge instead of triggering 1000+ live queries per page load.
* Fix: Sorting by Size without a prior scan no longer returns zero results.
* New: "Not scanned" status badge with muted style for files not yet analyzed.

= 3.3.7 =
* Fix: WooCommerce product gallery images (`_product_image_gallery`) now correctly detected as in-use — previously showed as unused on WooCommerce sites.
* Fix: ACF image and file fields storing attachment IDs now detected via ACF shadow key join — eliminates false positives on ACF-powered sites.
* Security: Replaced `data-imghtml` + `.html()` in delete modal with `data-imgurl` + `document.createElement('img')` — removes potential XSS vector in file preview.

= 3.3.6 =
* New: AJAX tab navigation — switching between Dashboard, Unused Files, Library, and Settings no longer triggers a full page reload. Includes skeleton loader for instant visual response.
* New: Adaptive scan engine — batch size auto-adjusts based on server throughput, memory limit, and PHP time limit. Starts at 5 items and grows or shrinks per batch to prevent timeouts on any hosting environment.
* New: Offset-based scan pagination — replaces page-based approach so batch size can safely change between requests without skipping or double-scanning files.
* Fix: `render_media_row()` no longer calls the full scanner on every page render — reads cached postmeta instead, reducing page load from 1000+ queries to ~20.
* Fix: Cron scan updated to use offset-based `scan_batch()` signature.

= 3.3.5 =
* Fix: Elementor CSS scan — now scans all `*.css` files in `uploads/elementor/css/` without a stale post-ID filter, using a 1-minute file-list transient and 2-minute per-file object cache.

= 3.3.1 =
* Fix: Fatal error on activation — `oliverodev_media_audit_get_filesystem()` called `includes()` which is not a valid PHP or WordPress function. Replaced with a direct `filesize()` call; WP_Filesystem is unnecessary for reading file sizes.

= 3.3.0 =
* New: CSS background-image detection — now detects media files used in inline styles and custom CSS (parallax backgrounds, hero sections, etc). Fixes issue where background images were incorrectly marked as unused and deleted.
* New: Private method `check_css_background_usage()` searches for `url()` patterns in post_content and postmeta.
* Fix: RevSlider table check was using undefined `$terms` variable — changed to `$search_terms`.

= 3.2.9 =
* Fix: "Where is it used?" now correctly finds Gutenberg block references. Previously returned "No specific location found" for images inserted via Gutenberg blocks because the search only used the full URL. Now also searches for `wp-image-{id}` CSS class and `"id":N` JSON block patterns — matching all detection methods used by the scanner.

= 3.2.8 =
* Improved: PRO upgrade banner completely redesigned with benefit-focused messaging, risk-oriented copy, and trial-first CTA ("Try PRO Free — 3 Days"). Dynamic unused file count shown in the banner copy.

= 3.2.7 =
* New: "Where is it used?" button on every Used file — shows the exact posts, pages, or theme settings referencing each file.
* New: Real-time scan counter — displays "Scanning X of Y files · Z remaining" during active scans.
* New: Delete confirmation modal — shows file thumbnail, name, and size before permanent deletion instead of a plain browser dialog.
* New: Export CSV — download a spreadsheet of all unused files directly from the Unused Files tab.

= 3.2.6 =
* Fix: Removed false positive detections caused by overly broad serialized-integer patterns (`i:N;`, `,N,`, `,N"`) being matched in postmeta, usermeta, termmeta, and options tables.
* Fix: Removed unreliable exact-integer postmeta match that incorrectly flagged files as used when unrelated meta keys (e.g. `_edit_last`, counters) happened to store the same number as a media ID.
* Improvement: JSON and HTML id-based patterns (`"id":N`, `data-id="N"`, etc.) are now scoped exclusively to post_content where Gutenberg blocks live, eliminating false positives in meta tables.

= 3.2.5 =
* Hide the "Get PRO" banner when PRO is already active via license integration.

= 3.2.4 =
* Maintenance release for WordPress.org package compliance and metadata alignment.

= 3.2.3 =
* Fixed PRO upgrade link to Freemius checkout.

= 3.2.2 =
* Added animated PRO upgrade banner in dashboard.
* Added link to upgrade to PRO version.

= 3.2.1 =
* Improved WordPress.org submission compatibility and plugin metadata.
* Added plugin text domain loading.
* Refreshed compatibility metadata.

= 3.2.0 =
* Refactored scanning architecture for improved performance.
* Updated compatibility metadata.

== Upgrade Notice ==

= 3.2.7 =
New: See exactly where each file is used, real-time scan progress, safer delete confirmation modal, and CSV export of unused files.

= 3.2.6 =
Important fix: resolves false positives that caused unused media files to appear as "Used". Upgrade recommended for all users.

= 3.2.5 =
Minor update. Hides the PRO upgrade banner when the PRO add-on is already active.
