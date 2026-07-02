# Install

## Requirements

- WooCommerce 7+
- PHP 7.4+
- WordPress 6.0+

## Steps

1. Copy (or clone) the plugin into `wp-content/plugins/wc-pricebook`.
2. Activate **WC Pricebook** in **Plugins** (WooCommerce must be active).
3. Configure under **WooCommerce → Pricebook**.

## No Composer required

The plugin ships a built-in PSR-4 autoloader and only prefers Composer's autoloader if a
`vendor/` directory exists. So it runs on any host without a build step.

`composer install` is only needed for the development tooling (PHPUnit) — see
[Development](/reference/architecture#development).
