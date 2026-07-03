# Price resolution

`PriceEngine::price_for_user()` resolves the price a customer sees in a fixed order.
The first step that applies wins.

1. **Manager switcher override** — when a manager is previewing as a tier/customer.
2. **Per-customer price** set on the product — a negotiated price beats everything.
3. **`call_for_price` rule** — force the price empty ("Call for Price").
4. **Hide-Pricing visibility role** the customer matches → empty price, *unless* the
   product force-shows the price to this customer.
5. **Category → role mapping** (optional, per user).
6. **`skip_matrix` rule** — leave the incoming price untouched (everyone sees MSRP).
7. **Tier resolution** among the tiers the customer holds:
   **`always` override** › **`when_priced` override** › **lowest remaining tier price**.

## Within a single tier

`price_as_tier()` computes one tier's price:

1. MSRP (regular price) as the starting point.
2. The tier's **explicit price** for the product, if set.
3. **`no_tier_discount`** collapses a discount tier to its plain base price.
4. **Multiplier fallback** — `multiplier × base role's price` when no explicit price.
5. **Chained `fallback_to`** — MSRP or another tier.

## Zero prices

The effective price a customer sees (`effective_price()`, the lowest of the resolved
regular and sale prices) treats an exact `0` as "not yet priced" and blanks it to an
empty price ("Call for Price"). This matches stores where `0` is a placeholder rather
than a genuine free price.

A store that sells real $0 products can keep the zero by returning `true` from the
[`wc_pricebook_allow_zero_price`](/reference/filters#pricing) filter — globally or per
product/user:

```php
add_filter( 'wc_pricebook_allow_zero_price', '__return_true' );
```

## Visibility vs. price

"Hidden" comes in two flavors, and they're independent of the number above:

- **Hide Product** removes the product from the catalog entirely.
- **Hide Pricing** keeps the product visible but empties the price ("Call for Price").

Both are driven by [visibility roles](./visibility) and can be overridden per product by
[force overrides](./force-overrides).
