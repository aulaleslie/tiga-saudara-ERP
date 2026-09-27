## 1. Dispatch demand and user workflow

- [x] 1.1 Update standard Sales Dispatch aggregation to include non-stock parent products and non-stock bundle components with an explicit inventory/non-inventory presentation flag.
- [x] 1.2 Update the Dispatch Livewire table and Blade view so non-stock rows retain ordered, approved, and requested quantity inputs but omit location, available-stock, and serial controls.
- [x] 1.3 Preserve existing aggregation keys and independent parent/component quantities, including the non-stock service-parent and stock-managed RAM-component bundle case.

## 2. Dispatch submission and approval boundaries

- [x] 2.1 Update standard Dispatch submission validation to accept a positive non-stock acknowledgement quantity and validate authoritative remaining quantity for both classifications.
- [x] 2.2 Require location, stock, and serial validation only for stock-managed submitted rows; keep forged-key and cross-setting location protections intact.
- [x] 2.3 Persist non-stock acknowledgement details through the existing pending Dispatch workflow with null inventory-specific fields, while retaining normal stock detail persistence.
- [x] 2.4 Guard Dispatch approval so non-stock details skip all product-stock/product quantity mutation, serial processing/history, and inventory transaction creation.

## 3. Completion status and regressions

- [x] 3.1 Recalculate standard Sale Dispatch status from all parent and component fulfilment obligations and approved Dispatch details, yielding partial status until every required quantity is approved.
- [x] 3.2 Preserve rejection recalculation so rejected non-stock acknowledgements do not count toward completion.
- [x] 3.3 Replace the current non-stock tests that expect service-only Dispatch rejection, service-detail exclusion, and stock-only mixed completion with the new acknowledgement behavior.
- [x] 3.4 Add focused coverage for service-only partial/full Dispatch, mixed Sales, no-inventory approval side effects, rejected acknowledgements, and the service-bundle/RAM quantity multiplication case.
- [x] 3.5 Run focused standard Sales Dispatch and non-stock regression tests; record separately any unrelated SQLite test-environment failures.

## 4. Scope protection

- [x] 4.1 Verify POS posting and POS Dispatch tests remain unchanged and that the implementation is limited to standard Sales Dispatch paths.
