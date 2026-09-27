## Why

Sales-context product quick-add currently forces the product to be sellable but still accepts an absent sale price because the shared creation rules treat that field as nullable. This can insert a newly created product into a sales cart with zero sellable pricing, while quick-add price replication across businesses is only indirectly covered by tests.

## What Changes

- Require a positive sale price when the shared product quick-add modal is submitted from Sales.
- Keep purchase-context quick-add behavior conditional on the sellable toggle and avoid changing normal product-edit business scoping.
- Preserve the shared product-creation path that creates identical initial `product_prices` rows for every business.
- Add focused regression coverage proving Sales rejects a missing sale price and proving Purchase and Sales quick-add persist the intended initial prices across all businesses.
- Limit verification to the touched quick-add behavior and newly added or updated tests; no full-suite test run is required.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sale-product-quick-add`: Make positive sellable pricing explicit and enforceable for Sales-context quick-add before product creation or cart insertion.
- `product-creation`: Specify that products created through Purchase or Sales quick-add receive identical initial setting-scoped price rows for every existing business.

## Impact

- Affects the shared Livewire product quick-add validation path and its focused Purchase/Sales feature tests.
- Reuses `Modules\Product\Services\ProductCreator`; no database migration, import/background-job work, or API contract change is required.
- Does not change normal product edit behavior, which remains scoped to the current business.
