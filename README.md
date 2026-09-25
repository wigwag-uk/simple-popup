# Simple Popup

WordPress popup plugin by m.n.vougiouka. Popups are managed from their own "Popups" section in the WordPress admin sidebar.

## Install
Download `simple-popup.zip` from the [latest release](https://github.com/wigwag-uk/simple-popup/releases/latest) and upload it in WordPress > Plugins > Add New > Upload Plugin.

## Updates
Sites running 3.5.0 or later check this repository and show "Update available" in WordPress admin > Plugins.

## Releasing a new version
1. Change the code in `simple-popup/`.
2. Bump `Version:` in `simple-popup/simple-popup.php` and add an entry at the top of `simple-popup/changelog.txt`.
3. Push to `main`. The "Release plugin" GitHub Action checks the PHP, builds `simple-popup.zip` and publishes release `v<version>` automatically.
