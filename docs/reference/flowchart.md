# The price flowchart

The flowchart is a manager-only debug page that explains exactly how a price resolves
for any product and customer. It's an optional module (toggle it in the settings).

## Opening it

- **`/price-flowchart`** — the interactive page.
- **`/price-flowchart?product_id=…&as_user=…`** — deep-link to a specific product and
  customer.

Product and customer fields are search-as-you-type. Products are searchable by **title
or SKU**.

## What it shows

For the selected product and customer:

- the product's **categories** and **flags**, and the **pricing rules in effect**
  (including rules set by a per-product checkbox, shown as "Set on this product");
- the price for **every tier**, with the resolution steps;
- the **final price** the chosen customer gets and **why** — including whether the
  product is hidden from their catalog, whether the price is hidden ("Call for Price"),
  and whether they are priced through a **parent account**.

Use it to answer "why does this customer see this price?" without reading code.
