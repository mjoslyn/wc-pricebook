# Architecture

PSR-4 `WCPricebook\` → `src/`.

| File | Responsibility |
|---|---|
| `wc-pricebook.php` | Bootstrap: constants, autoload, WC guard, boot, CLI registration |
| `src/Plugin.php` | Service container; wires hooks and modules in `boot()` |
| `src/Config.php` | All configuration; option-backed (`wc_pricebook_config`) and filterable |
| `src/Context.php` | Resolves the pricing user, manager status, tier & visibility membership |
| `src/PriceEngine.php` | The core: `price_as_tier()` and `price_for_user()` |
| `src/Rules.php` | Named behaviors → taxonomy terms / per-product flags |
| `src/WooHooks.php` | WooCommerce price / cart / visibility filters |
| `src/Admin/Settings.php` | Settings page (tiers, visibility roles) |
| `src/Admin/ProductMeta.php` | Product-data "Pricebook" tab |
| `src/Admin/UserProfile.php` | Per-user My Products / include-exclude / category roles |
| `src/Switcher/` | Manager admin-bar pricing switcher |
| `src/Flowchart/Flowchart.php` | `/price-flowchart` debug page |

## Design rule

Anything a store needs is reachable from settings/options or a filter — adoption is a
data migration, not a code fork. No store-specific IDs, roles, or names live in `src/`.

## Autoloading

The plugin prefers Composer's autoloader if `vendor/` exists, else a built-in SPL
autoloader in `wc-pricebook.php`. New classes need no manual wiring.

## Development

Requires Docker and Node.

```bash
npm install            # @wordpress/env
npm run env:start      # WordPress + WooCommerce at http://localhost:8888 (admin / password)
composer install       # dev deps (PHPUnit) — the plugin runs without Composer
npm test               # vendor/bin/phpunit — standalone, no Docker/DB needed
```

The PHPUnit suite is **standalone**: `tests/bootstrap.php` shims the WP functions the
engine uses with an in-memory store, so it's fast and needs no database. Add a test in
`tests/*Test.php` for any engine/context/rules change.
