## 1. Customer Resolver Lazy Fallback

- [x] 1.1 Update `PosCheckoutCustomerResolverService::resolve()` to lazily fallback to `Setting.pos_walk_in_customer_id` with `resolution_source: 'walk_in'` when `selectedCustomerId` is null
- [x] 1.2 Keep `PosCartSessionStore::emptyCart()` clean with `selected_customer_id: null` and `selected_customer_tier: null`

## 2. Frontend & Gating Adaptation

- [x] 2.1 Update `sell.blade.php` to render `resolution_source: 'walk_in'` with a `Default` badge
- [x] 2.2 Update `sell.blade.php` checkout / draft button enablement to treat `resolution_source === 'walk_in'` as a valid customer selection
- [x] 2.3 Ensure `clearCartButton` is only enabled when items exist or explicit customer is selected (`resolution_source === 'selected'`)

## 3. Checkout Protection & Fallback

- [x] 3.1 Verify `CUSTOMER_UNRESOLVED` guard continues to reject checkout when no customer is selected and no walk-in is configured
- [x] 3.2 Verify checkout finalization succeeds seamlessly when walk-in is configured without requiring explicit customer selection

## 4. Tests

- [x] 4.1 Feature tests in `POSWalkInCustomerSelectionTest` validating page load, cart clear, post-checkout, and multi-terminal resolution
- [x] 4.2 Checkout tests in `POSCheckoutSelectedCustomerRequiredTest` verifying lazy walk-in resolution at checkout and rejection when no customer/default is available
- [x] 4.3 Run focused Pos tests to verify no regressions

## 5. Documentation & Spec Sync

- [x] 5.1 Update `proposal.md`, `design.md`, and `specs/pos-cart-default-walkin-customer/spec.md` with lazy resolution architecture
