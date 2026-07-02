# Multi-account / sub-accounts

Many B2B stores let a dealer have multiple logins (a primary account plus staff
sub-accounts) that should all be priced the same. WC Pricebook supports this with a
single resolution point.

## The resolved pricing user

Everything — pricing, visibility, force overrides, category-role mappings — runs on the
**resolved pricing user**, not the raw logged-in one. A store implements sub-account →
parent resolution once, in the `wc_pricebook_pricing_user` filter:

```php
add_filter( 'wc_pricebook_pricing_user', function ( $user ) {
    // Return the parent WP_User when $user is a sub-account.
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
