# Visibility roles

A **visibility role** hides products — or their prices — for a chosen audience on a
chosen set of categories. Build them in **WooCommerce → Pricebook → Visibility roles**.

Each role has three parts:

## 1. Audience

Match by **roles** and/or **specific users**:

- **Roles** — pick any WordPress roles, with **ANY** or **ALL** matching.
- **Specific users** — pick individual customers (matched in addition to the roles).
- **MSRP Customer** — a synthetic role that matches anyone with **no pricing tier**
  (retail customers, subscribers, guests).

An empty audience (no roles, no users) matches nobody. Roles-only, users-only, or both
all work.

## 2. Categories

The same category-set control as tiers: All / Only selected / All except selected.

## 3. Hide action

- **Hide Product** — remove the matched products from the catalog for these users.
- **Hide Pricing** — keep them visible but show **Call for Price** (empty price).

## Example: "price requires a tier"

> **Audience:** MSRP Customer · **Categories:** Special Order · **Hide:** Hide Pricing

Retail/no-tier shoppers see *Call for Price* on Special Order items; anyone holding a
pricing tier sees their price. This single builder replaces a dedicated "price requires
tier" rule.

## Per-user targeting and multi-account

Matching runs on the **resolved pricing user**, so a sub-account is treated as its
parent. When you target **specific users**, pick the **parent/primary account** — its
sub-accounts are covered automatically. See [Multi-account](./multi-account).
