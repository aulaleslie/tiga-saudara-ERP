## 1. Product-scoped authorization

- [x] 1.1 Create `app/Policies/ProductPolicy.php` (if it does not already exist) with `uomNormalize(User $user, Product $product): bool`: checks `stock_managed === true`, `merged_into_id === null`, Super Admin bypass, and `purchases.received.uom-normalize` permission. No Purchase-status check.
- [x] 1.2 Register the policy for `Product` in the relevant policy provider (matching how `PurchasePolicy` is registered).

## 2. Product-scoped routes and controller
 
- [x] 2.1 Add `/products/{product}/uom-normalize` (edit), `/products/{product}/uom-normalize/preview`, `/products/{product}/uom-normalize/units/search`, `/products/{product}/uom-normalize/candidate-lines` to `Modules/Product/Routes/web.php`, gated by `can:uomNormalize` (or `Gate::authorize` in the controller, matching existing convention).
- [x] 2.2 Create `Modules/Product/Http/Controllers/UomNormalizationController.php` with `edit()`, `searchUnits()`, `candidateLines()`, `preview()`, `store()`, ported from the Purchase controller, keyed by the route-bound `Product` instead of `Purchase`. Drop `searchProducts()` and `productBelongsToPurchase()` entirely (no product-search step, no boundary to check).
- [x] 2.3 Pass `session('setting_id')` directly to `UomNormalizationEligibilityService`/`UomNormalizationExecutionService` calls (previously `$purchase->setting_id`).
- [x] 2.4 Do not port `history()` (unused in the current UI; dropped per design decision).

## 3. Product-scoped view
 
- [x] 3.1 Create `Modules/Product/Resources/views/uom-normalization/edit.blade.php`, based on the existing Purchase view, with: product identity/current base unit shown as a static header (no product-search step); unit search + factor input; candidate lines auto-loaded and pre-selected once factor is valid, but still individually togglable; reason field; preview; two acknowledgment checkboxes; confirm modal; execute.
- [x] 3.2 Update all `route('purchases.uom-normalize.*', ...)` references in the new view's Alpine.js script to the new `route('products.uom-normalize.*', $product->id)` names.
- [x] 3.3 Add a "Normalisasi UOM" button/entry point on `Modules/Product/Resources/views/products/show.blade.php`, wrapped in `@can('uomNormalize', $product)`, linking to `route('products.uom-normalize.edit', $product->id)`.

## 4. Remove the old Purchase-scoped entry point
 
- [x] 4.1 Remove the `purchases.uom-normalize.*` route group from `Modules/Purchase/Routes/web.php`.
- [x] 4.2 Delete `Modules/Purchase/Http/Controllers/UomNormalizationController.php`.
- [x] 4.3 Delete `Modules/Purchase/Resources/views/uom-normalization/edit.blade.php`.
- [x] 4.4 Remove the "Normalisasi UOM Penerimaan" button block (`@can('uomNormalize', $purchase)` ... ) from `Modules/Purchase/Resources/views/show.blade.php`.
- [x] 4.5 Remove `PurchasePolicy::uomNormalize()` once no route/view references it.

## 5. Tests
 
- [x] 5.1 Update `Modules/Purchase/Tests/Feature/UomNormalizationTest.php` to target the new product-scoped routes/policy (relocate to `Modules/Product/Tests/Feature/` if that matches existing module test conventions).
- [x] 5.2 Update `Modules/Purchase/Tests/Feature/UomNormalizationEndToEndTest.php` similarly.
- [x] 5.3 Update `Modules/Purchase/Tests/Feature/UomNormalizationMigrationTest.php` similarly (verify it only asserts against `UomNormalizationBatch`/`UomNormalizationLine` schema, not the old routes — update only what actually references the old entry point).
- [x] 5.4 Add/verify a test covering "product with no eligible purchase/receipt history can still open the page and sees an informative empty state" (new behavior vs. the old 403-on-ineligible-Purchase-status).
- [x] 5.5 Add/verify a test covering "candidate lines default to fully selected once target unit and factor are set."
- [x] 5.6 Run `composer test:fresh-sqlite` (or `php artisan test` filtered to the UOM normalization suite) and confirm green.

## 6. Manual verification
 
- [x] 6.1 From a real product's show page, click through: open UOM normalization → set target unit/factor → confirm candidate lines are pre-selected → enter reason → preview → acknowledge both checkboxes → execute → confirm redirect/success and correct product/stock/price updates.
- [x] 6.2 Confirm the old `/purchases/{purchase}/uom-normalize` route now 404s and the button no longer appears on the Purchase show page.
