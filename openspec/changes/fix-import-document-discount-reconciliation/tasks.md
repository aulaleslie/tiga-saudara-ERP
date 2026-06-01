## 1. Test Coverage

- [x] 1.1 Add a focused purchase import test using `ONLINE SBY / INV/2026/002725`-style data proving repeated `Diskon` is applied once, `discount_amount` is persisted, `discount_percentage` is zero, and payment reconciliation succeeds.
- [x] 1.2 Add a focused sales import test using `JL.2021.17756`-style data proving repeated `Diskon` is applied once, `discount_amount` is persisted, `discount_percentage` is zero, and payment reconciliation succeeds.
- [x] 1.3 Add purchase and sales import tests proving repeated `Biaya Pengiriman` is applied once per invoice group and reconciles with source `Total`.
- [x] 1.4 Add purchase and sales import tests proving conflicting repeated `Diskon` values invalidate the whole invoice group without creating documents or payment rows.
- [x] 1.5 Add purchase and sales import tests proving conflicting repeated `Biaya Pengiriman` values invalidate the whole invoice group without creating documents or payment rows.
- [x] 1.6 Add or update tests proving `Diskon %` is ignored for import math when `Diskon` amount is present and rounded percent would drift from source `Total`.

## 2. Import Row Mapping

- [x] 2.1 Extend purchase import header normalization, staging jobs, and controller mapping to preserve document-level `Diskon` separately from `Diskon Per Baris %`.
- [x] 2.2 Extend sales import header normalization, staging jobs, and controller mapping to preserve document-level `Diskon` separately from `Diskon Per Baris %`.
- [x] 2.3 Preserve `Diskon %` in staged row data only if useful for diagnostics, but do not use it for import calculations.

## 3. Shared Adjustment Resolution

- [x] 3.1 Add a small reusable resolver/helper, or equivalent duplicated local helpers, that parses and resolves one distinct document-level money value per invoice group.
- [x] 3.2 Use the resolver/helper to resolve document `Diskon` with zero as the fallback when the field is blank or missing.
- [x] 3.3 Use the resolver/helper to resolve document `Biaya Pengiriman` with zero as the fallback when the field is blank or missing.
- [x] 3.4 Ensure conflicting non-blank repeated adjustment values throw clear runtime errors that mark the current invoice group invalid.

## 4. Purchase Import Implementation

- [x] 4.1 Update purchase import total calculation to compute adjusted document total as line total minus resolved document discount plus resolved shipping.
- [x] 4.2 Pass the adjusted purchase document total to `ImportPaymentSummaryResolver::resolve()`.
- [x] 4.3 Persist purchase `discount_percentage = 0`, `discount_amount` equal to resolved document `Diskon`, `shipping_amount` equal to resolved shipping, and `total_amount` equal to the adjusted total.
- [x] 4.4 Preserve existing purchase `Diskon Per Baris %` line discount behavior and detail discount fields.

## 5. Sales Import Implementation

- [x] 5.1 Update sales import total calculation to compute adjusted document total as line total minus resolved document discount plus resolved shipping.
- [x] 5.2 Pass the adjusted sales document total to `ImportPaymentSummaryResolver::resolve()`.
- [x] 5.3 Persist sale `discount_percentage = 0`, `discount_amount` equal to resolved document `Diskon`, `shipping_amount` equal to resolved shipping, and `total_amount` equal to the adjusted total.
- [x] 5.4 Ensure document `Diskon` does not populate sales detail product discounts.

## 6. Verification

- [x] 6.1 Run the focused purchase import payment and document adjustment tests.
- [x] 6.2 Run the focused sales import payment and document adjustment tests.
- [x] 6.3 Run existing purchase and sales import regression tests covering payment ledger, ownership, and price synchronization.
- [x] 6.4 Run `php artisan test` or `composer test:fresh-sqlite` when practical for broader confidence.
