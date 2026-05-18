=== OliveroDev Media Audit – Media Library Cleaner & Optimizer ===
Contributors: oliverodev, alexis-olivero
Tags: media cleaner, media library, unused media, media cleanup, media optimizer
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.2.5
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

= 3.2.5 =
Minor update. Hides the PRO upgrade banner when the PRO add-on is already active.
