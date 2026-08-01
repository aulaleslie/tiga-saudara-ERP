# Tasks

## 1. Strengthen tax-inclusive line-total tests

- [x] 1.1 Pass the tax id as the third `updateTax` argument in every affected test.
- [x] 1.2 Set the intended tax-inclusion mode explicitly in each affected test.
- [x] 1.3 Assert the tax id is stored on the cart line (`options->product_tax`).
- [x] 1.4 Assert `product_tax_amount`, `sub_total_before_tax`, and `sub_total`,
      including the sum-consistency relation.
- [x] 1.5 Retain `unit_price`, requested Total Baris, and
      `pricing_source = manual_line_total` assertions.
- [x] 1.6 Rename or remove tests that claim tax involvement but cannot carry tax
      under the non-PKP force-null rule.
      Renamed `test_line_total_non_pkp_with_tax_reverses_correctly` →
      `test_line_total_non_pkp_ignores_tax_and_reverses_correctly`, and
      `test_line_total_non_pkp_tax_included_reverses_correctly` →
      `test_line_total_non_pkp_tax_included_mode_extracts_no_tax`.

## 2. Production fix for the exposed defect

- [x] 2.1 Null the `tax_id` in `ProductCart::updateLineTotal()` for non-PKP sale
      carts, matching the existing rule in `calculateSubtotalAndTax()`, so the
      entered Total Baris is not divided by a tax rate that is never added back.
- [x] 2.2 Confirm the guard leaves PKP reverse calculation unchanged.

## 3. Verification

- [x] 3.1 Run `php artisan test tests/Feature/Livewire/SaleProductCartLineTotalCalculationMatrixTest.php`
      — 16 passed, 73 assertions.
- [x] 3.2 Run `php artisan test tests/Feature/Livewire/SalePricingSourceMigrationAndPersistenceTest.php`
      — 16 passed, 99 assertions.
- [x] 3.3 Report whether any production defect was exposed.
      Yes — the non-PKP Total Baris reverse-calculation defect documented in
      proposal.md, fixed under task 2.1.
- [x] 3.4 Regression-check the wider `Sale|Purchase` suite: 133 failed / 1200
      passed before the fix, 132 failed / 1201 passed after. One test flipped to
      passing, nothing regressed; remaining failures are pre-existing.
