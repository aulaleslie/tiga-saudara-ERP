## 1. Bundle Price Field Sync

- [x] 1.1 Update bundle item price input rendering so Alpine state is re-initialized when selected product/price context changes.
- [x] 1.2 Keep Livewire as source of truth for `items.*.informational_item_price` and ensure active-setting sale price assignment path remains unchanged.
- [x] 1.3 Verify the visible `Harga Informasi Item` value appears immediately after product selection in create and edit flows.

## 2. Regression Coverage

- [x] 2.1 Add/adjust tests that assert item informational price defaults from `product_prices.sale_price` for active setting on selection.
- [x] 2.2 Add/adjust tests that protect against blank visible state after Livewire update/morph for selected rows.
- [x] 2.3 Run focused test suite for product bundle table/editor and confirm no regressions in bundle create/edit submission behavior.

## 3. Validation and Release Readiness

- [x] 3.1 Perform manual QA on `/products/{id}/bundles/create` and edit page: select, change, clear, reselect, add/remove row paths.
- [x] 3.2 Confirm submitted payload remains numeric and consistent with visible value in the bundle item row.
- [x] 3.3 Document rollout notes and fallback verification steps in change discussion.
