# Quick start

A minimal dealer-pricing setup, end to end.

## 1. Create the roles you price against

WC Pricebook **reads** roles, it never creates them. Use a role manager (or code) to
create e.g. `dealer` and `operator`. Each tier's **Role slug** is shown in the tier
editor so you can match them up.

## 2. Add tiers

In **WooCommerce → Pricebook → Tiers**, add a tier per role:

- **Role** — the WP role/capability (e.g. `dealer`).
- **Categories** — which products this tier prices.
- **Price override** — how it competes with other tiers a customer holds.
- **Fallback to** — MSRP or another tier when nothing else resolves.

## 3. Set tier prices on products

On a product, open the **Pricebook** tab and set the tier prices (and, optionally,
quantity breaks and per-customer prices). Tier prices are stored in each tier's price
meta key.

## 4. Gate the catalog or price (optional)

In **Pricebook → Visibility roles**, add a role such as:

> **Audience:** MSRP Customer · **Categories:** *Parts* · **Hide:** Hide Pricing

That means "retail / no-tier customers see *Call for Price* on Parts" — i.e. the price
requires a tier.

## 5. Preview

- Use the **admin-bar switcher** to view the store as any tier or as a specific customer.
- Or open **`/price-flowchart`** to see exactly how a price resolves.

Next: read the [concepts](/concepts/tiers) to go deeper.
