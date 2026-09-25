# Simple Popup: notes for Claude

WordPress plugin, source in `simple-popup/`. Sites update from GitHub Releases of this repo (wigwag-uk/simple-popup, public).

## Every change
- Bump `Version:` in `simple-popup/simple-popup.php` (patch for fixes/tweaks, minor for features).
- Add an entry at the TOP of `simple-popup/changelog.txt`: version on its own line, then `- ` bullet lines, then a blank line.
- Run `php -l` on every changed PHP file.
- Push to `main` (the user wants changes pushed directly). The GitHub Action `.github/workflows/release.yml` builds and publishes the release. Never commit a built zip.

## Decisions to keep
- Plugin author: m.n.vougiouka.
- Only administrators (`manage_options`, constant SP_CAP) can manage popups; REST API for `sp_popup` is locked to admins.
- All settings go through `sp_sanitize_settings()` on save AND on read. New settings must be added to `sp_defaults()` and `sp_sanitize_settings()`.
- The sites' theme (Page Builder Framework) already provides the `wpbf-grid` CSS (70/30, 60/40 on small screens). Do not ship grid CSS.
- Header right padding is 36px (desktop and mobile).
- Content padding defaults: desktop `20px 20px 20px 20px`, mobile (<780px) `20px 12px 20px 12px`. Under 780px popups are 95% wide.
- Default corners are square (radius 0).
- Updater: `simple-popup/includes/class-sp-updater.php`, reads the latest release, requires the asset to be named `simple-popup.zip`, verifies GitHub's sha256 digest before install.
- Some sites set `DISALLOW_FILE_MODS`, which blocks updates from wp-admin on those sites.
