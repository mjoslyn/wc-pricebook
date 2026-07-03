# Pricelist export

Export a CSV of the **resolved price for every user against every product** — one row
per (user, product) pair. Each price is resolved through the full engine for that user
(tiers, per-customer overrides, category → role mapping, visibility gating), exactly as
the customer would see it.

## Columns

```
display_name,roles,product,sku,resolved_price
Jane Doe,"operator, dealer",Widget A,WID-A,83.00
Jane Doe,"operator, dealer",Widget B,WID-B,120.00
Bob Smith,customer,Widget A,WID-A,120.00
```

A hidden / "Call for Price" price — and, by default, a resolved price of `0` — is written
as an empty cell (see [Zero prices](#zero-prices)).

## Running it

Three ways, all production-safe. Users and products are gathered in pages, so it scales
on large stores.

### WP-CLI

Headless, no login required:

```bash
# Write a file (prints the path)
wp wc-pricebook export-pricelist --file=/tmp/pricelist.csv

# Email the CSV as an attachment
wp wc-pricebook export-pricelist --email=sales@example.com

# Limit to roles, and email the configured recipient
wp wc-pricebook export-pricelist --roles=dealer,operator --send
```

| Flag | Meaning |
|---|---|
| `--file=<path>` | Write the CSV to this path (default: a temp file, path printed). |
| `--email=<address>` | Email the CSV to this address as an attachment. |
| `--roles=<slugs>` | Comma-separated WP roles to limit the export to (default: every user). |
| `--send` | Email the CSV to the configured recipient (or the site admin). |

### Scheduled (WP-Cron)

Set **WooCommerce → Pricebook → Pricelist export** to *Daily* or *Weekly*. The CSV is
generated and emailed to the configured recipient automatically. The schedule syncs
whenever the settings are saved, and is cleared on plugin deactivation.

### On demand

The **Generate and email now** button on that settings page builds the CSV immediately
and emails it to the recipient.

## Settings

Found at **WooCommerce → Pricebook → Pricelist export**:

- **Email recipient** — where the scheduled export is emailed. Defaults to the signed-in
  admin's address; leave blank to fall back to the site `admin_email`.
- **Schedule** — *Off* (manual / WP-CLI only), *Daily*, or *Weekly*.
- **Limit to roles** — optionally restrict the export to users in selected roles.

## Zero prices

By default a resolved price of exactly `0` is treated as "not yet priced" and written as
an empty cell (WooCommerce "Call for Price"), matching stores where `0` is a placeholder
rather than a genuine free price. To keep real $0 prices, return `true` from
[`wc_pricebook_allow_zero_price`](/reference/filters#pricing).

::: warning Product Bundles
The exporter prices a bundle from the **bundle's own price meta**, not by summing its
bundled items. A bundle whose base price is zeroed so its own plugin can price it (e.g.
via a "set bundle price to zero" flag) will export as an empty price. Price such bundles
explicitly on the product if you need a figure in the export.
:::

## Filters

| Filter | Overrides |
|---|---|
| `wc_pricebook_export_settings` | Recipient / schedule / role filter |
| `wc_pricebook_export_product_ids` | The product refs (`id`, `name`, `sku`) included |
| `wc_pricebook_export_user_query` | The `WP_User_Query` args used to gather users |
