# Pricing tiers

A **tier** is a small record edited in **WooCommerce → Pricebook → Tiers**.

| Field | Meaning |
|---|---|
| **Role** | The WP role/capability whose holders belong to this tier (the tier *key*). |
| **Label** | Display name used in the switcher, tables, and flowchart. |
| **Multiplier / Base role** | Compute the price as `multiplier × base role's price` when the tier has no explicit price. |
| **Fallback to** | MSRP, another tier, or none — used when nothing else resolves. |
| **Price override** | How this tier competes with the other tiers a customer holds (see below). |
| **Categories** | The products this tier prices: All / Only selected / All except selected. |
| **Notes** | Free-form documentation for the tier. |

## Membership is a capability check

A user belongs to tier `gold` when `user_can( $user, 'gold' )` is true. By default that
matches a **role named `gold`** — WordPress exposes every role as a same-named
capability — but it also matches any role that **grants** a `gold` capability, so you can
name roles however you like.

The **same capability check** is used everywhere a role is targeted — [visibility
roles](./visibility), per-product [force overrides](./force-overrides), and bulk
pricing — so "target `gold`" means the same thing throughout.

The plugin **never creates, deletes, or assigns roles** — that stays with the store (or a
role manager). Override membership entirely with the `wc_pricebook_user_tier` filter.

## Override semantics

When a customer holds more than one tier, each tier's **Price override** decides how it
competes:

- **Competes on price** *(lowest wins)* — the tier's price is a candidate; the lowest
  candidate wins.
- **Overrides when this tier has its own price** *(`when_priced`)* — if the tier has an
  explicit price for the product, it takes over (distributor-style).
- **Always overrides (in its category scope)** *(`always`)* — inside its categories, the
  tier wins outright (category-scoped, e.g. "this account pays MSRP on category X").

Precedence when several apply: **`always` › `when_priced` › lowest candidate.** A tier
that is out of its category scope is inert — it contributes nothing.

## Category scope

Every tier (and visibility role) uses the same category-set control:

- **All products**
- **Only selected categories** (include)
- **All except selected categories** (exclude)
