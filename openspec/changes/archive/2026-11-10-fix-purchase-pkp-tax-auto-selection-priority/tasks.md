# Tasks

## 1. Purchase Cart Tax Priority Implementation

- [x] 1.1 Update `resolveDefaultTaxId()` in `app/Livewire/Purchase/ProductCart.php` to remove the first-tax fallback and return `null` when no tax has `is_default = true`
- [x] 1.2 Update `resolvePreferredPkpAutoTaxId()` in `app/Livewire/Purchase/ProductCart.php` to check default tax (priority 1) before product tax (priority 2), and return `null` if neither is found
- [x] 1.3 Ensure `calculate()` in `app/Livewire/Purchase/ProductCart.php` resolves tax using `resolvePreferredPkpAutoTaxId()` for consistency

## 2. Test Verification

- [x] 2.1 Verify `tests/Feature/Livewire/PurchaseProductCartNoDefaultTaxTest.php` passes completely
- [x] 2.2 Add unit/feature tests for the priority scenarios: default tax priority over product tax, product tax used when no default exists, null tax when neither exists
- [x] 2.3 Run full purchase suite (`php artisan test --filter=Purchase`) and ensure no regressions
