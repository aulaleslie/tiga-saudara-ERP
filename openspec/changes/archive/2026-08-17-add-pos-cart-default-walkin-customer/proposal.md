## Why

Cashiers must manually pick "Umum"/walk-in customer on nearly every cart today, even though businesses can already configure a default walk-in customer (`Setting.pos_walk_in_customer_id`) in Business Settings. That setting is currently only consulted during split/multi-source checkout resolution — it has no effect on the ordinary single-terminal POS cart resolver, so cashiers must redundantly select walk-in customer.

## What Changes

- When resolving the customer for a POS cart (via `PosCheckoutCustomerResolverService` and `PosCartService::buildSnapshot()`), if `selected_customer_id` is null and the setting has `pos_walk_in_customer_id` configured, the resolver SHALL resolve the walk-in customer with `resolution_source: 'walk_in'` (mirroring `PosCheckoutGroupCustomerResolverService`).
- If an explicit customer is chosen by the cashier, `selected_customer_id` takes precedence with `resolution_source: 'selected'`.
- If no walk-in customer is configured for the setting (`pos_walk_in_customer_id` is null) and no customer is selected, resolution source remains `'none'`.
- Checkout finalization accepts `resolution_source: 'walk_in'` seamlessly, while preserving `CUSTOMER_UNRESOLVED` when neither an explicit customer is selected nor a walk-in default is configured.
- The UI in `sell.blade.php` displays the default walk-in customer badge and enables checkout and draft-saving without requiring manual selection.
- **Excluded**: loading an existing DRAFT transaction with an explicit customer preserves that customer.

## Capabilities

### New Capabilities
- `pos-cart-default-walkin-customer`: Defines lazy fallback resolution of `Setting.pos_walk_in_customer_id` with `resolution_source: 'walk_in'` across single-terminal cart display, draft saving, and checkout finalization.

### Modified Capabilities
(none)

## Impact

- `Modules/Pos/Services/PosCheckoutCustomerResolverService.php` — resolves setting `pos_walk_in_customer_id` with `resolution_source: 'walk_in'` when `selected_customer_id` is null.
- `Modules/Pos/Resources/views/sell.blade.php` — handles `resolution_source: 'walk_in'` for customer badge rendering and checkout button enablement.
- No changes to database schema or settings tables.
