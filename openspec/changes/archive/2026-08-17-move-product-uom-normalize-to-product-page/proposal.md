## Why

The UOM normalization feature is only reachable from `/purchases/{purchase}/uom-normalize`, which forces the operator to first locate a specific received Purchase before they can search for the product and set its correction. The console command `product:convert-uom {product} {unit} {factor}` proves the underlying operation is really product-first (the operator already knows which product and what its correct base UOM should be); the purchase-first web entry point adds an unnecessary product-search step and ties eligibility to one Purchase's status even though the correction always acts on the product's complete purchase/receipt history in the active setting, not on a single Purchase. Moving the entry point to the Product page removes that detour and lines the web flow up with how operators actually think about the fix, while keeping the existing preview/select-lines/acknowledge/execute safety flow intact.

## What Changes

- **BREAKING**: Remove the `/purchases/{purchase}/uom-normalize*` routes, controller, view, and the "Normalisasi UOM Penerimaan" button on the Purchase show page.
- Add `/products/{product}/uom-normalize*` routes, controller, and view under the Product module, with the product preselected (no product-search step).
- Add a "Normalisasi UOM" entry point button on the Product show page, shown only when the product is eligible to be considered (stock-managed, not merged, tenant-scoped).
- Candidate purchase/receipt lines are auto-loaded and pre-selected (all-checked) as soon as target unit and factor are entered, instead of requiring the operator to manually check each line; the checkboxes remain visible and individually togglable for transparency and audit.
- Replace the Purchase-status-based authorization check (`uomNormalize(User, Purchase)`, which required a specific Purchase to be `RECEIVED`/`RECEIVED_PARTIALLY`) with a Product-scoped authorization check (`uomNormalize(User, Product)`) based on permission, stock-managed/not-merged status, and active-setting scope. A product with no eligible purchase history yet is still reachable; the "nothing to normalize" case is surfaced as a page state, not a 403.
- No changes to `UomNormalizationEligibilityService`, `UomNormalizationExecutionService`, `UomNormalizationBatch`/`UomNormalizationLine` entities, the preview/execute JSON contracts, the two execution acknowledgment checkboxes, or the underlying safety rules (product-wide completeness, cross-setting footprint blocking, transaction matching, barcode integrity).

## Capabilities

### Modified Capabilities
- `received-purchase-uom-normalization`: Entry point moves from Purchase-scoped (`/purchases/{purchase}/uom-normalize`, purchase-status-gated) to Product-scoped (`/products/{product}/uom-normalize`, product-eligibility-gated); product selection step is removed (product is already known from the route); candidate purchase/receipt lines default to fully selected instead of requiring manual selection. Preview, execution, safety eligibility, and audit requirements are unchanged.

## Impact

- **Routes**: `Modules/Purchase/Routes/web.php` (remove `purchases.uom-normalize.*`), `Modules/Product/Routes/web.php` (add `products.uom-normalize.*`).
- **Controllers**: Retire `Modules/Purchase/Http/Controllers/UomNormalizationController.php`; add `Modules/Product/Http/Controllers/UomNormalizationController.php` (drops the `productBelongsToPurchase` boundary check, since the product is now the route-bound resource).
- **Views**: Retire `Modules/Purchase/Resources/views/uom-normalization/edit.blade.php` and the button in `Modules/Purchase/Resources/views/show.blade.php`; add `Modules/Product/Resources/views/uom-normalization/edit.blade.php` and a button in `Modules/Product/Resources/views/products/show.blade.php`.
- **Authorization**: Retire `PurchasePolicy::uomNormalize()`; add an equivalent `ProductPolicy::uomNormalize()` (or extend the existing Product policy). Permission key `purchases.received.uom-normalize` is reused as-is (or renamed) — decided in design.
- **Services/Entities**: No changes to `UomNormalizationEligibilityService`, `UomNormalizationExecutionService`, `UomNormalizationBatch`, `UomNormalizationLine`.
- **Tests**: `Modules/Purchase/Tests/Feature/UomNormalizationTest.php`, `UomNormalizationEndToEndTest.php`, `UomNormalizationMigrationTest.php` need their route/controller assumptions updated to the new Product-scoped entry point.
