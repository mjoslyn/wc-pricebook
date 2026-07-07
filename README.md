# WC Pricebook

**Role-based pricing and catalog visibility for WooCommerce — driven by configuration, not code.**

WC Pricebook lets a store show different prices, and a different catalog, to different
customers: reseller tiers, distributor pricing, quantity breaks, per‑customer
negotiated prices, "Call for Price" gating, and per‑role/per‑user catalog visibility.
Everything is defined in a settings UI (and mirrored by filters), so the common cases
need **no custom code**.

- **Slug:** `wc-pricebook` · **Namespace:** `WCPricebook\` · **Text domain:** `wc-pricebook`
- **Requires:** WooCommerce 7+, PHP 7.4+, WordPress 6.0+
- **License:** GPL‑2.0‑or‑later

---

## Table of contents

- [Why](#why)
- [Features](#features)
- [Concepts](#concepts)
  - [Pricing tiers](#pricing-tiers)
  - [Price resolution order](#price-resolution-order)
  - [Visibility roles](#visibility-roles)
  - [Per-product force overrides](#per-product-force-overrides)
  - [Per-customer prices & quantity breaks](#per-customer-prices--quantity-breaks)
  - [Rules](#rules)
- [Install](#install)
- [Quick start](#quick-start)
- [The price flowchart](#the-price-flowchart)
- [Multi-account / sub-accounts](#multi-account--sub-accounts)
- [Filters & hooks](#filters--hooks)
- [Architecture](#architecture)
- [Development](#development)
- [Adopting an existing store (migration)](#adopting-an-existing-store-migration)

---

## Why

Most WooCommerce "B2B pricing" plugins bolt a fixed model onto the store. WC Pricebook
takes the opposite approach: **the pricing model is data**. A tier, a visibility rule,
a category scope, an override — all of it lives in one config option (and every value
is also a filter). That makes the model easy to reason about, test, and adapt to a
store's existing user roles instead of forcing new ones.

## Features

- **Configurable price tiers** — reseller, distributor, graduated volume tiers
  (Silver / Gold / Platinum)… each tier maps to a WordPress **role/capability** you
  already use.
- **Override semantics per tier** — *lowest‑wins*, *override when the tier has its own
  price* (distributor‑style), or *always override in a category scope*.
- **Visibility roles** — hide products from the catalog **or** show "Call for Price",
  for an audience matched by **roles and/or specific users** (ANY/ALL), scoped to
  categories.
- **Per‑product force overrides** — force a product (or its price) **visible** to
  specific roles/users, overriding any hide rule.
- **Per‑customer negotiated prices** and **quantity‑break (bulk) pricing** per product.
- **Manager pricing‑view switcher** — an admin‑bar control to preview the store as any
  tier or as a specific customer.
- **Price flowchart** — a manager‑only `/price-flowchart` page that shows exactly how a
  price resolves for any product and customer.
- **Catalog export** — a print‑ready **PDF** and a **CSV** of the catalog, priced per the
  current customer (or the full tier matrix for managers), from a shortcode with download
  buttons + URL‑only shortcodes. Off by default; every column, category, and gate is a filter.
- **Multi‑account aware** — sub‑accounts resolve to their parent for pricing and
  visibility, via a single filter.
- **Generic core, safe by default** — no store‑specific IDs or roles in the engine;
  WooCommerce hooks register only when WooCommerce is active; every knob has a filter.
- **Fast, standalone tests** — the PHPUnit suite shims WordPress in memory, so the
  engine is tested without booting WordPress or a database.

## Concepts

### Pricing tiers

A tier is a small record edited in **WooCommerce → Pricebook → Tiers**:

| Field | Meaning |
|---|---|
| **Role** | The WP role/capability whose holders belong to this tier (the tier *key*). |
| **Label** | Display name (switcher, tables, flowchart). |
| **Multiplier / Base role** | Compute the price as `multiplier × base role's price` when no explicit price is set. |
| **Fallback to** | MSRP, another tier, or none — used when nothing else resolves. |
| **Price override** | *Competes on price* · *Overrides when this tier has its own price* · *Always overrides (in its category scope)*. |
| **Categories** | The products this tier prices (All / Only selected / All except selected). |

Membership is a **capability check**: a user is in tier `gold` when `user_can( $user,
'gold' )` — which by default matches a role named `gold` (WordPress exposes each role as
a same‑named capability) or any role granting a `gold` capability. The same check is used
wherever a role is targeted (visibility roles, force overrides, bulk pricing). The plugin
never creates, deletes, or assigns
roles — that stays with the store (or a role manager).

### Price resolution order

`PriceEngine::price_for_user()` resolves a customer's price in this order:

1. **Manager switcher** override (preview mode).
2. **Per‑customer price** set on the product — beats everything.
3. **`call_for_price`** rule → empty price ("Call for Price").
4. **Hide‑Pricing visibility role** the customer matches → empty price (unless force‑price‑visible).
5. **Category→role mapping** (per‑user, optional).
6. **`skip_matrix`** rule → leave the incoming price untouched.
7. **Tier resolution** among the tiers the customer holds:
   **`always` override** › **`when_priced` override** › **lowest remaining tier price**.

### Visibility roles

Built in **WooCommerce → Pricebook → Visibility roles**. Each role targets an audience
and applies one action to a category scope:

- **Audience** — pick **roles** (with **ANY/ALL** matching) and/or **specific users**.
  A synthetic **"MSRP Customer"** matches anyone with no pricing tier (retail
  customers, subscribers, guests).
- **Categories** — All / Only selected / All except selected (same control as tiers).
- **Hide** — **Hide Product** (remove from the catalog) or **Hide Pricing**
  ("Call for Price"), for matched users on those categories.

This one builder expresses both catalog gating and "price requires a tier."

### Per-product force overrides

On the product editor's **Pricebook** tab, **Force visibility overrides** let a single
product opt specific **roles or users** out of any hide rule:

- **Force product visible to roles/users** — always show the product in the catalog.
- **Force price visible to roles/users** — always show its price.

### Per-customer prices & quantity breaks

Also on the product's **Pricebook** tab:

- **Customer‑specific prices** — a negotiated `{ customer, price }` that wins over every
  tier for that customer.
- **Quantity‑break (bulk) pricing** — `{ role, min qty, price }` rows targeting any
  pricing tier, WP role, or MSRP Customer; the lowest applicable price for the roles a
  customer holds wins as quantities change in the cart.

### Rules

Named product behaviors, triggered by a **per‑product checkbox**, a **taxonomy term**,
or the `wc_pricebook_rules` filter:

- **`skip_matrix`** — ignore tier pricing; everyone sees MSRP.
- **`no_tier_discount`** — discount tiers collapse to the plain base (reseller) price.
- **`force_visible`** — a product‑level "always show the price" flag.
- **`call_for_price`** — force the price empty ("Call for Price") for everyone.

## Install

1. Download the latest **`wc-pricebook-vX.Y.Z.zip`** from
   [Releases](https://github.com/mjoslyn/wc-pricebook/releases).
2. WP admin → **Plugins → Add New → Upload Plugin** → the zip → **Install Now** →
   **Activate** (WooCommerce must be active).
3. Configure under **WooCommerce → Pricebook**.

**Updates:** distributed via GitHub, so WordPress won't show update notices on its own.
Install the free [Git Updater](https://git-updater.com/) plugin to get in-dashboard
updates from this repo's releases (the plugin already carries the required headers), or
just upload a newer release zip to update manually. See the
[install docs](https://mjoslyn.github.io/wc-pricebook/guide/install) for details.

The plugin runs **without Composer** — it ships a PSR‑4 autoloader and prefers
Composer's autoloader only if `vendor/` exists. `composer install` is for dev tooling
(PHPUnit) only.

## Quick start

1. **Create the WP roles** you price against (e.g. `reseller`, `distributor`) with a member
   plugin or code — the plugin reads them, it doesn't create them. Each tier's **Role
   slug** is shown in the tier editor.
2. **Add tiers** in *Pricebook → Tiers* (role, categories, override, fallback).
3. **Set tier prices** on products via the **Pricebook** product‑data tab (or the tier's
   price meta keys).
4. **Add visibility roles** if you gate the catalog or price (e.g. *MSRP Customer →
   Hide Pricing* on a category = "price requires a tier").
5. **Preview** with the admin‑bar switcher, or open `/price-flowchart`.

## The price flowchart

Managers can open **`/price-flowchart`** (or `/price-flowchart?product_id=…&as_user=…`)
to see, for any product and customer:

- the product's categories, flags, and the pricing rules in effect;
- the price for every tier and the resolution steps;
- the exact price the chosen customer gets and **why**, including catalog‑visibility and
  "Call for Price" status, and whether the customer is priced through a **parent
  account**.

Product and customer fields are search‑as‑you‑type (products searchable by **title or
SKU**).

## Pricelist export

Export a CSV of the **resolved price for every user against every product** — one row
per (user, product) pair, with columns `display_name, roles, product, sku,
resolved_price`. A hidden / "Call for Price" price is written as an empty cell. Each
price is resolved through the full engine for that user (tiers, per‑user overrides,
category→role mapping, visibility gating), exactly as the customer would see it.

Three ways to run it, all production‑safe:

- **WP‑CLI** — headless, no login required:

  ```bash
  wp wc-pricebook export-pricelist --file=/tmp/pricelist.csv       # write a file
  wp wc-pricebook export-pricelist --email=sales@example.com        # email it
  wp wc-pricebook export-pricelist --roles=dealer,operator --send   # limit + email configured recipient
  ```

- **Scheduled (WP‑Cron)** — set **WooCommerce → Pricebook → Pricelist export** to
  *Daily* or *Weekly*; the CSV is emailed to the configured recipient automatically.

- **On demand** — the **Generate and email now** button on that settings page.

The **recipient** defaults to the signed‑in admin's email (falling back to the site
`admin_email` when blank), and an optional **role filter** limits the export to users in
selected roles.

The scheduled run and the **Generate and email now** button run **in the background via
Action Scheduler** (bundled with WooCommerce): users are processed in bounded batches so
no single request builds the whole file — this is what keeps a large store from timing
out. The email arrives when the last batch finishes. WP‑CLI stays synchronous by default
(no request timeout); pass `--async` to use the background batches instead.

## Multi-account / sub-accounts

Pricing and visibility always run on the **resolved pricing user**. A store implements
sub‑account → parent resolution in one place — the `wc_pricebook_pricing_user` filter —
and every sub‑account then inherits its parent's tiers, visibility, and force overrides.
Target the **parent** account in a visibility role or override and it covers the whole
family.

## Filters & hooks

Every configurable value is also a filter (escape hatch), so a theme/plugin can override
without touching the option:

| Filter | Overrides |
|---|---|
| `wc_pricebook_tiers` | Tier definitions |
| `wc_pricebook_rules` | Rule → taxonomy bindings |
| `wc_pricebook_visibility_roles` | Visibility role definitions |
| `wc_pricebook_pricing_user` | Resolve the effective (parent) pricing user |
| `wc_pricebook_is_manager` | Whether a user is a pricing manager |
| `wc_pricebook_user_tier` | Whether a user belongs to a tier |
| `wc_pricebook_category_roles` | A user's category→role mappings |
| `wc_pricebook_user_meta_keys` / `wc_pricebook_user_pricing_meta` | Point the plugin at a store's existing meta keys |
| `wc_pricebook_base_meta` / `wc_pricebook_bulk_pricing_meta` | MSRP / bulk‑pricing meta keys |
| `wc_pricebook_allow_zero_price` | Keep a resolved $0 as a real price (default: blank to "Call for Price") |
| `wc_pricebook_export_settings` | Pricelist‑export recipient / schedule / role filter |
| `wc_pricebook_export_product_ids` / `wc_pricebook_export_user_query` | Products / user query for the pricelist export |
| `wc_pricebook_catalog_pdf_enabled` | Turn the catalog PDF/CSV export on (default off) |
| `wc_pricebook_catalog_pdf_columns` / `wc_pricebook_catalog_pdf_show_full_matrix` | Which tier columns show, and who sees the full matrix vs their own price |
| `wc_pricebook_catalog_pdf_excluded_categories` / `wc_pricebook_catalog_pdf_skip_product` | Categories / products left out of the catalog |

## Architecture

PSR‑4 `WCPricebook\` → `src/`.

| File | Responsibility |
|---|---|
| `wc-pricebook.php` | Bootstrap: constants, autoload, WC guard, boot, CLI registration |
| `src/Plugin.php` | Service container; wires hooks and modules |
| `src/Config.php` | All configuration; option‑backed and filterable |
| `src/Context.php` | Resolves the pricing user, manager status, tier & visibility membership |
| `src/PriceEngine.php` | The core: `price_as_tier()` and `price_for_user()` |
| `src/Rules.php` | Named behaviors → taxonomy terms / per‑product flags |
| `src/WooHooks.php` | WC price / cart / visibility filters |
| `src/Admin/Settings.php` | Settings page (tiers, visibility roles) |
| `src/Admin/ProductMeta.php` | Product‑data "Pricebook" tab |
| `src/Admin/UserProfile.php` | Per‑user My Products / include‑exclude / category roles |
| `src/Switcher/` | Manager admin‑bar pricing switcher |
| `src/Flowchart/Flowchart.php` | `/price-flowchart` debug page |
| `src/Export/` | Per‑user pricelist CSV export (WP‑CLI, cron, "Send now") |
| `src/CatalogPdf/CatalogPdf.php` | Catalog PDF (mPDF) + CSV export; download buttons and URL shortcodes |

**Design rule:** anything a store needs is reachable from settings/options or a filter —
adoption is a data migration, not a code fork. No store‑specific IDs, roles, or names
live in `src/`.

## Development

Requires Docker and Node.

```bash
npm install            # @wordpress/env
npm run env:start      # WordPress + WooCommerce at http://localhost:8888 (admin / password)
composer install       # dev deps (PHPUnit) — the plugin itself runs without Composer
npm test               # vendor/bin/phpunit — standalone, no Docker/DB needed
npm run env:stop
npm run env:destroy

# WP-CLI inside the env:
npx wp-env run cli wp <command>
```

The PHPUnit suite is **standalone**: `tests/bootstrap.php` shims the handful of WP
functions the engine uses with an in‑memory store, so it's fast and needs no database.
Add a test in `tests/*Test.php` for any engine/context/rules change.

## Adopting an existing store (migration)

A store adopts WC Pricebook by **writing config**, not editing code: point the tiers,
rules, visibility roles, and meta keys at what the store already has, using the settings
UI or the [filters](#filters--hooks). Nothing destructive happens to product or user
data — the entire model is one option value.

For a large cutover, encode that mapping in your own private adapter (a mu‑plugin or a
WP‑CLI command that calls `update_option( 'wc_pricebook_config', … )`) so it's
repeatable and reversible. Keep store‑specific IDs and roles in that adapter, never in
this plugin.

## Documentation

Full docs: **https://mjoslyn.github.io/wc-pricebook/** — source in [`/docs`](docs/),
publishable with **GitHub Pages** or **Netlify** (see [`docs/README.md`](docs/README.md)).

## License

GPL‑2.0‑or‑later.
