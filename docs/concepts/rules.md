# Rules

**Rules** are named product behaviors. Each can be triggered three ways:

- a **per-product checkbox** on the Pricebook tab (for `skip_matrix` and
  `no_tier_discount`),
- a **taxonomy term** bound to the rule in config, or
- the **`wc_pricebook_rules`** filter.

## The rules

| Rule | Effect |
|---|---|
| **`skip_matrix`** | Ignore tier pricing for the product — every customer sees MSRP. |
| **`no_tier_discount`** | Discount tiers (dealer-4/8/12/15…) collapse to the plain base (dealer) price. |
| **`force_visible`** | Product-level "always show the price" flag. |
| **`call_for_price`** | Force the price empty ("Call for Price") for everyone. |

## Per-product checkboxes

On the product's **Pricebook** tab, the **Pricing rules** section exposes `skip_matrix`
and `no_tier_discount` as checkboxes. Checking one sets a per-product flag that the
engine honors in addition to any configured taxonomy binding — no taxonomy required.

## Binding a rule to a taxonomy

For bulk control, bind a rule to taxonomy terms in config (or via `wc_pricebook_rules`):
any product carrying one of those terms triggers the rule. Useful when a store already
tags products with a "flag" taxonomy.
