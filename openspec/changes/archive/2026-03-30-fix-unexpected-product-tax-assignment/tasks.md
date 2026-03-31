## 1. Cart Components Refactoring

- [x] 1.1 Remove fallback to default/latest tax in `app/Livewire/Purchase/ProductCart.php` within the `resolvePreferredPkpAutoTaxId` method.
- [x] 1.2 Remove fallback to default/latest tax in `app/Livewire/Sale/ProductCart.php` within the `resolvePreferredPkpAutoTaxId` method.
- [x] 1.3 Update `Modules/Purchase/Resources/views/includes/product-cart-alpine.blade.php` to remove the recursive fallback to `this.taxes[0].id` when `isPkp` is true.

## 2. Product Import Refactoring

- [x] 2.1 Update `Modules/Product/Http/Controllers/ProductController.php` to remove hardcoded `tax_id = 1` in the `handleCsvUpload` method.

## 3. Test Verification

- [x] 3.1 Update `tests/Feature/Livewire/PurchaseProductCartPkpTaxReconciliationTest.php` to assert that taxes remain NULL when not explicitly provided, even in PKP mode.
- [x] 3.2 Update `tests/Feature/Livewire/SaleProductCartPkpTaxReconciliationTest.php` to assert NULL tax behavior.
- [x] 3.3 Update any other failing tests (e.g., `PurchaseProductCartDefaultTaxTest.php`) that previously relied on auto-assignment.
- [x] 3.4 Run the full suite of cart and product creation tests to verify the fix and ensure no regressions in tax calculation for explicitly taxed items.
