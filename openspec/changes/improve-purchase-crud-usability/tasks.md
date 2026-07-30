## 1. Purchase-cart navigation and narrow note maintenance

- [x] 1.1 Add a permission-gated cross-business price link to the selected product name in the shared purchase cart.
- [x] 1.2 Create the setting-scoped, non-archived inline purchase-note editor using the supplier purchase-number editor's authorization and interaction pattern.
- [x] 1.3 Add the note editor to the normal purchase detail view without exposing it in global read-only detail mode.
- [x] 1.4 Add focused create/edit purchase-cart tests for authorized and unauthorized cross-business price-link visibility.
- [x] 1.5 Add Livewire tests for note updates on fully received purchases and denied archived, foreign-setting, or unauthorized note updates on the purchase detail page.

## 2. Tax-aware final line-total entry

- [x] 2.1 Extend the shared purchase cart state and row UI with a localized editable `Total Baris` field and clarification that it excludes global discount and shipping.
- [x] 2.2 Implement final-total input validation and reverse-calculation of unit price for quantity, fixed discount, percentage discount, selected tax, and tax-inclusion mode.
- [x] 2.3 Reuse canonical subtotal/tax calculation and cart updates so normalized create/edit persistence retains coherent `price`, `unit_price`, discount, DPP, tax, and subtotal values.
- [x] 2.4 Add Livewire and persistence tests for tax-included and tax-excluded PKP rows, non-PKP rows, multi-quantity rows, fixed and percentage discounts, header adjustments, rounding, and invalid input.
  - **Canonical module:** `resources/js/purchaseCalculatorHelper.js` (loaded via app.js Vite build)
  - **Alpine component:** `Modules/Purchase/Resources/views/includes/product-cart-alpine.blade.php` (uses imported helper, no inline duplicate)
  - **JavaScript tests:** 24/24 passing (validation, 11% tax-included/excluded with/without discounts, multi-qty, forward/reverse pairing)
  - **Alpine integration tests:** 3/3 passing (module exists, Alpine uses imported helper, validation tests pass)
  - **Livewire line-total tests:** 7/7 passing (validation, reverse calculation, tax-included/excluded, fixed/percentage discounts, 100% discount)
  - **Persistence tests:** 5/5 passing (normalized create/edit persistence with tax-included/excluded)
  - **Test execution verified:** All tests pass with zero incomplete tests, canonical reverse calculator fixed to match forward calculator contract

## 3. Purchase receiving validation feedback

- [x] 3.1 Add a visible, accessible validation summary and field-level Bahasa Indonesia errors to the purchase receiving form.
- [x] 3.2 Ensure the location picker receives and renders the required-location invalid state after a rejected form POST.
- [x] 3.3 Replace the zero-quantity browser alert with the shared visible validation feedback while keeping server validation authoritative.
- [x] 3.4 Add feature coverage for missing location and all-zero quantities, asserting no receiving note is created and submitted values/messages are retained.

## 4. Verification

- [x] 4.1 Run the relevant focused Laravel feature and Livewire test suites and resolve regressions.
- [x] 4.2 Run the project PHP formatting/lint checks required by the modified files.
