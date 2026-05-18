# OliveroDev Media Audit (FREE)

Clean your WordPress media library, detect unused files with smart scanning, and optimize storage with a fast workflow.

## Overview

**OliveroDev Media Audit** helps you identify which media files are actually used in your site and which ones can be safely removed.

This repository contains the **FREE version** of the plugin.

- WordPress.org readme format lives in `readme.txt`.
- GitHub documentation is provided in this `README.md`.

## Features (FREE)

- Smart scan of media references in posts, pages, widgets, options, and metadata.
- Dashboard with **used vs unused** counts.
- Estimated storage savings from unused files.
- Batch scanning for better performance on large libraries.
- Scheduled scans via WP-Cron.
- Support for common image formats (JPEG, PNG, GIF, WebP, SVG).

## PRO Features

The PRO add-on (separate repository/package) unlocks:

- Deep detection integrations (ACF, Divi, Elementor, WooCommerce, and more).
- Advanced storage insights and visual analysis.
- Extended file-type workflows (documents, audio, video, archives).
- Enhanced cleanup operations and automation.

## Installation

1. Upload plugin files to `/wp-content/plugins/oliverodev-media-audit`.
2. Activate from **Plugins** in WordPress admin.
3. Open **Tools > OliveroDev Media Audit**.
4. Run a scan and review used/unused files.
5. Remove unused files after validation.

## Requirements

- WordPress `>= 5.0`
- PHP `>= 7.4`

## Version

- Current stable: `3.2.5`

## WordPress.org Packaging Notes

- Keep `readme.txt` in WordPress.org format for SVN releases.
- Keep plugin header version in sync with `Stable tag`.
- Tag releases in SVN (`/tags/x.y.z`) and align GitHub release notes.

## Contributing

1. Create a feature branch.
2. Follow WordPress Coding Standards (sanitization, escaping, capability checks, hook naming).
3. Test in a local WordPress environment.
4. Open a pull request with clear scope and changelog note.

## License

GPLv2 or later. See `LICENSE.txt`.
