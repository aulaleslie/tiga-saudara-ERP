## 1. Price Presentation Normalization

- [x] 1.1 Add a single zero-decimal normalization path for cross-business price values, including stored decimal strings and validation-restored input, using nearest-whole-Rupiah rounding.
- [x] 1.2 Apply the normalized representation to `value` and `data-original` for sale, tier 1, tier 2, and last-purchase inputs and to the read-only average-purchase field before `maskMoney` initialization.
- [x] 1.3 Verify the existing edit, cancel, unmask-on-submit, optimistic-lock version, and disabled average-price behavior continue to use the normalized values without changing backend persistence rules.

## 2. Regression Coverage

- [x] 2.1 Add focused rendering regression tests covering all five fields and proving `2500000.00` is emitted at the `2500000` magnitude rather than `250000000`.
- [x] 2.2 Add coverage for nearest-whole-Rupiah handling, validation-restored input, and cancel restoration through the project's practical Blade/JavaScript test boundary.
- [x] 2.3 Add an unchanged-form round-trip test proving a loaded `2500000.00` value remains numerically `2500000.00` after save while average price and tax metadata remain unchanged.

## 3. Verification

- [x] 3.1 Run the focused Product module cross-business price feature tests and resolve regressions caused by this change.
- [x] 3.2 Run the broader relevant Laravel test set with `php artisan test` or `composer test:fresh-sqlite` as proportional confidence permits, and document any unrelated pre-existing failures.
