# Force overrides

Where [visibility roles](./visibility) *hide* things store-wide, **force overrides** let
a single product *always show* itself (or its price) to specific roles or users —
overriding any hide rule.

Set them on the product editor's **Pricebook** tab, under **Force visibility overrides**.

## Force product visible

- **to roles** — these roles always see the product in the catalog.
- **to users** — these specific customers always see it.

Overrides a **Hide Product** visibility role.

## Force price visible

- **to roles** — these roles always see the product's price.
- **to users** — these specific customers always see it.

Overrides a **Hide Pricing** visibility role (and price gating).

## Per-customer prices & quantity breaks

The **Pricebook** tab also carries two per-product pricing tools:

- **Customer-specific prices** — a negotiated `{ customer, price }` that wins over every
  tier for that customer.
- **Quantity-break (bulk) pricing** — `{ role, min qty, price }` rows targeting any
  pricing tier, WP role, or MSRP Customer; the lowest applicable price for the roles a
  customer holds wins as quantities change in the cart.

## Multi-account note

Like visibility roles, force overrides run on the **resolved pricing user**. Target the
**parent** account and every sub-account under it inherits the override.
