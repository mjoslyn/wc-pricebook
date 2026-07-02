# Filters & hooks

Every configurable value is also a filter, so a theme or companion plugin can override
it without touching the stored option.

## Configuration

| Filter | Overrides |
|---|---|
| `wc_pricebook_tiers` | Tier definitions |
| `wc_pricebook_rules` | Rule → taxonomy bindings |
| `wc_pricebook_visibility_roles` | Visibility role definitions |

## Identity & membership

| Filter | Overrides |
|---|---|
| `wc_pricebook_pricing_user` | Resolve the effective (parent) pricing user — see [Multi-account](/concepts/multi-account) |
| `wc_pricebook_is_manager` | Whether a user is a pricing manager |
| `wc_pricebook_user_tier` | Whether a user belongs to a given tier |
| `wc_pricebook_category_roles` | A user's category → role mappings |

## Meta keys

Point the plugin at a store's existing meta instead of its defaults:

| Filter | Overrides |
|---|---|
| `wc_pricebook_user_meta_keys` | Per-user meta keys (My Products, include/exclude categories, category roles) |
| `wc_pricebook_user_pricing_meta` | Per-customer product-price override meta key |
| `wc_pricebook_base_meta` | MSRP / base price meta key |
| `wc_pricebook_bulk_pricing_meta` | Quantity-break meta key |

## Example

```php
// Treat a custom capability as the "distributor" tier.
add_filter( 'wc_pricebook_user_tier', function ( $has, $user_id, $tier_key ) {
    if ( 'distributor' === $tier_key ) {
        return user_can( $user_id, 'b2b_customer' );
    }
    return $has;
}, 10, 3 );
```
