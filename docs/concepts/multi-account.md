# Multi-account / sub-accounts

Many B2B stores let a business account have multiple logins (a primary account plus staff
sub-accounts) that should all be priced the same. WC Pricebook supports this with a
single resolution point.

::: tip Opt-in — no default
Multi-account resolution is **opt-in**. The plugin ships **no** default resolver: out of
the box the `wc_pricebook_pricing_user` filter returns the logged-in user unchanged, so
every account is priced as itself. Add the filter below to enable sub-accounts. (There's
no universal "sub-account" model in WooCommerce, so the plugin can't guess the parent
relationship — you point it at whatever meta/plugin links your accounts.)
:::

## The resolved pricing user

Everything — pricing, visibility, force overrides, category-role mappings — runs on the
**resolved pricing user**, not the raw logged-in one. A store implements sub-account →
parent resolution once, in the `wc_pricebook_pricing_user` filter:

```php
add_filter( 'wc_pricebook_pricing_user', function ( $user ) {
    // Return the parent WP_User when $user is a sub-account.
    // 'parent_account' is a placeholder — use whatever meta key (or B2B/membership
    // plugin API) your store already uses to link a sub-account to its parent.
    $parent_id = (int) get_user_meta( $user->ID, 'parent_account', true );
    return $parent_id ? get_user_by( 'id', $parent_id ) : $user;
} );
```

After that, every sub-account inherits its parent's tiers, visibility, and overrides.

## Targeting a family

Because matching happens on the resolved parent:

- **Role targeting** — a sub-account gets whatever its parent's roles get.
- **Specific-user targeting** — put the **parent account's** ID in a visibility role or
  force override, and it covers the parent **and all its sub-accounts**. Listing an
  individual sub-account's own ID has no effect, since that user resolves to its parent
  before the match.

This is intentional parent-level behavior: sub-accounts are treated as their parent,
consistent with how pricing resolves.
