## Why

POS bundle carts support serial entry only on the bundle parent cart line. When a bundle *component* product is itself `serial_number_required`, POS has no UI, request field, or service-layer path to capture, persist, or validate a serial for that component — `StorePosCartSerialAssignmentRequest` and the split-planner's `assigned_serials` are keyed only to `lineId` (the parent). Normal (non-POS) Sales dispatch already handles this correctly via a composite `product_id-tax_id-bundle_id` key, so the pattern to extend from is proven; POS is the gap. Cashiers currently have no way to sell a bundle containing a serial-tracked component, or the system silently allows checkout without ever recording that component's serial.

## What Changes

- Add a bundle-detail modal reachable by clicking a bundle row in the POS cart, showing the bundle's components and a serial-entry field for each component flagged `serial_number_required`.
- Reuse the existing continuous-scan input component (dedup, Enter-as-submit suppression) inside the modal, auto-advancing to the next empty serial slot within the active component once its scanned-serial count matches its required quantity.
- Extend `StorePosCartSerialAssignmentRequest` and the checkout split planner's `assigned_serials` structure to carry a per-component dimension (component/bundle-item identifier) alongside the existing parent-line serial assignment, mirroring the `product_id-tax_id-bundle_id` composite key already used by normal-Sales dispatch.
- Persist partially-entered component serials on saved POS drafts by extending the existing bundle cart-line JSON payload — no new table.
- Gate checkout: a bundle row is blocked from checkout while the parent (if serial-required) or any serial-required component has fewer assigned serials than its required quantity. Reuse the existing `pos-checkout-serial-stock-validation` fulfillment-check pattern, extended to iterate bundle components.
- Add a normal-Sales dispatch regression test driving a `serial_number_required=true` bundle component through the existing (already-working, currently untested) serial branch. No normal-Sales application code changes are expected.

## Capabilities

### New Capabilities
- `pos-bundle-component-serial-entry`: Bundle-detail modal UX and per-component serial capture/persistence for POS bundle cart lines, including draft partial-entry persistence.

### Modified Capabilities
- `pos-checkout-serial-stock-validation`: Preflight/finalize fulfillment checks extend to validate serial-required bundle components, not only parent lines.
- `pos-checkout-split-posting`: Split planner's `assigned_serials` handling extends to carry and consume per-component serial assignments when partitioning bundle stock across owners.

## Impact

- **Affected code**: `Modules/Pos/Http/Requests/StorePosCartSerialAssignmentRequest.php`, `PosCheckoutSplitPlannerService.php`, POS cart Livewire component(s) and bundle-detail modal view (new), POS draft persistence (cart-line JSON schema), `Modules/Sale/Tests` (new regression test only, no app code).
- **Not affected**: `dispatch_details` schema, normal-Sales dispatch controller/service logic (already correct), bundle definition/pricing/lifecycle subsystems.
- **Testing**: Touched-file/implementation-focused test runs only (e.g. `php artisan test --filter=...`); no full suite run required for this change.
