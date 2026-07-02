# Introduction

WC Pricebook is a WooCommerce plugin for **role-based pricing and catalog visibility**.
It lets a store show different prices — and a different catalog — to different customers:
reseller tiers, distributor pricing, quantity breaks, per-customer negotiated
prices, "Call for Price" gating, and per-role/per-user catalog visibility.

## The core idea: the model is data

Most WooCommerce B2B pricing plugins bolt a fixed model onto the store. WC Pricebook
takes the opposite approach — **the pricing model is configuration**. A tier, a
visibility rule, a category scope, an override — all of it lives in one config option,
and every value is also exposed as a filter.

That means:

- **No new roles are forced on you.** Tiers map to the WordPress roles you already use.
- **Adoption is a data migration, not a code fork.** The engine (`src/`) contains no
  store-specific IDs, roles, or names.
- **Everything has an escape hatch.** Any option can be overridden with a filter.

## What's next

- [Install](./install) the plugin.
- Follow the [Quick start](./quick-start).
- Learn the [concepts](/concepts/tiers): tiers, resolution order, visibility roles,
  force overrides, and rules.
