# Install

## Requirements

- WooCommerce 7+
- PHP 7.4+
- WordPress 6.0+

## Install from a release (recommended)

1. Download the latest **`wc-pricebook-vX.Y.Z.zip`** from the
   [Releases page](https://github.com/mjoslyn/wc-pricebook/releases).
2. In WP admin: **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now**.
3. **Activate** it (WooCommerce must be active).
4. Configure under **WooCommerce → Pricebook**.

The release zip is a clean build — it unzips to a `wc-pricebook/` folder with only the
runtime files, so it installs like any normal plugin.

## Install from source

For development, or to track `main`, copy (or clone) the repo into
`wp-content/plugins/wc-pricebook`, then activate it. See
[Development](/reference/architecture#development) for the dev environment.

## Automatic updates (Git Updater)

Because this plugin is distributed via GitHub (not the wordpress.org directory),
WordPress won't show update notices for it on its own. To get updates in the dashboard,
the **site owner** installs the free [Git Updater](https://git-updater.com/) plugin once.

Git Updater reads these headers already present in `wc-pricebook.php`:

```
GitHub Plugin URI: mjoslyn/wc-pricebook
Primary Branch:    main
```

It then checks this repo's releases and surfaces a new version as a normal **Update now**
entry on the Plugins screen — no manual re-upload needed.

- Git Updater runs on the **site**, not inside this plugin. Nothing here needs it; the
  headers sit dormant unless a site has Git Updater installed.
- The repo is public, so **no access token** is required. (Private repos would need one
  configured in Git Updater.)
- Prefer not to use it? You can always update manually by uploading the newer release zip
  (WordPress replaces the plugin in place).

## No Composer required

The plugin ships a built-in PSR-4 autoloader and only prefers Composer's autoloader if a
`vendor/` directory exists. So it runs on any host without a build step.

`composer install` is only needed for the development tooling (PHPUnit) — see
[Development](/reference/architecture#development).
