# Catalog export

A print-ready **PDF** and a **CSV** of the product catalog, priced for the current customer.
Both come from one module (`src/CatalogPdf/CatalogPdf.php`) and one download endpoint; the PDF
is rendered with [mPDF](https://mpdf.github.io/) (a Composer dependency of the plugin).

The module is **off by default**. A host opts in with a single filter:

```php
add_filter( 'wc_pricebook_catalog_pdf_enabled', '__return_true' );
```

## What it renders

- **PDF** — a letter-format catalog grouped by product category, with a header built from your
  site identity (Customizer logo + site name). Each viewer sees either:
  - **their own price** — MSRP + a "Your Price" column (`price_for_user`), or
  - the **full tier matrix** — MSRP + one column per tier (`price_as_tier`) — for viewers the
    `wc_pricebook_catalog_pdf_show_full_matrix` filter returns `true` for (managers by default).
- **CSV** — one row per product: `SKU, Name, Price, Description, Category, URL`. `Price` is the
  viewer's own price; `URL` is a spreadsheet `=HYPERLINK(...)` cell that reads "Click to View".

Products and categories can be trimmed with `wc_pricebook_catalog_pdf_excluded_categories`
(term IDs) and `wc_pricebook_catalog_pdf_skip_product` (`$skip`, `$product_id`).

## Shortcodes

| Shortcode | Default tag | Output |
|---|---|---|
| Buttons | `wc_pricebook_catalog` | "Download (PDF)" + "Download (CSV)" buttons. `format="pdf"\|"csv"\|"both"` |
| PDF URL | `catalog_pdf_url` | Just the PDF download URL (for a custom link/button) |
| CSV URL | `catalog_csv_url` | Just the CSV download URL |

All three tags are filterable — see [Filters & hooks](/reference/filters#catalog-export).

## Download endpoint

The buttons and URL shortcodes point at `admin-post.php?action=wc_pricebook_catalog_pdf&format=pdf|csv`,
protected by a WordPress nonce. Because the nonce is embedded in the rendered link, take care
when placing these on a **page-cached** URL — a cached nonce can expire while the page persists.

## Example — a store-specific catalog

```php
// Turn it on and bind it to a store's own shortcode tag.
add_filter( 'wc_pricebook_catalog_pdf_enabled', '__return_true' );
add_filter( 'wc_pricebook_catalog_pdf_shortcode', fn() => 'print-catalog-full' );

// Everyone sees their own price; only managers get the full matrix (the default).
// Leave categories 15 and 218 out of the catalog.
add_filter( 'wc_pricebook_catalog_pdf_excluded_categories', fn() => array( 15, 218 ) );
```
