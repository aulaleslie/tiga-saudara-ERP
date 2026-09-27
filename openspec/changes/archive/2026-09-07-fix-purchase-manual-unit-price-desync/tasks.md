## 1. Audit sibling cart mutators

- [x] 1.1 Read `updateUnit()`, `updateTax()`, `updateQuantity()`, `setProductDiscount()`, `updateLineTotal()` in `app/Livewire/Purchase/ProductCart.php` and determine, for each, whether it (a) can change the row's effective unit price and (b) omits a fresh `unit_price` key from its `options` merge, leaving a stale `options.unit_price` behind.
- [x] 1.2 Record which mutators match both conditions in a short note in this change's directory (or as a code comment at the fix site) so the fix list in section 2 is traceable back to this audit.

## 2. Fix the cart write path

- [x] 2.1 In `ProductCart::updatePrice()`, add `unit_price` (the canonical/base-unit price, consistent with `canonical_unit_price`) to the `options` merge array so it can never be read back stale.
- [x] 2.2 Apply the same fix to every sibling mutator identified in 1.1/1.2 as sharing the defect.
- [x] 2.3 Manually exercise the create/edit purchase form in the browser: add a base-unit row, edit its unit price, confirm the displayed unit price and row total both update immediately and agree; repeat for a row with a unit conversion factor > 1.

## 3. Fix the normalizer read path

- [x] 3.1 In `Modules/Purchase/Services/PurchaseNormalizer.php::normalizeDetail()`, reorder the `$unitPrice` resolution (and the `$rawPrice` fed into `PurchaseUomConversionService::convert()`) so the cart item's top-level `price`/`entered_unit_price` takes precedence over `options.unit_price` when they disagree.
- [x] 3.2 Confirm `PurchaseUomConversionService::convert()`'s existing "unchanged historical" shortcut (comparing `storedEnteredPrice` to the incoming price) still behaves correctly once fed the corrected, non-stale price — no changes expected there, but re-read it against the new call site to confirm.

## 4. Focused verification of the code fix

- [x] 4.1 Add or extend a Pest/PHPUnit test in `Modules/Purchase/Tests/Feature/` that reproduces the original bug: create a purchase, edit a row's unit price via the cart flow (or by calling `PurchaseNormalizer::normalize()` directly with a cart payload shaped like `updatePrice()`'s output), and assert the persisted `unit_price`/`price` matches the new price and stays consistent with `sub_total`.
- [x] 4.2 Run only the affected test files with a focused filter (e.g. `php artisan test --filter=<TestClassName>` or the relevant `Modules/Purchase/Tests/Feature/PurchaseCartUomConversionTest.php` / `PurchaseRowTotalRoundingTest.php` if they cover this path) — do not run the full suite.

## 5. Backfill the 41 known-affected rows

- [x] 5.1 Write a one-off Artisan command (e.g. `purchase:fix-manual-unit-price-desync`) that, given the known list of affected `purchase_details` IDs (re-derived via the same detection query used during exploration: `pricing_source = 'manual_unit_price'` rows where `unit_price`/`sub_total` disagree beyond rounding tolerance, accounting for discount/tax/is_tax_included), computes the corrected `unit_price`/`price` from each row's own `sub_total_before_tax`, `product_discount_amount`, and `quantity`.
- [x] 5.2 Implement a `--dry-run` mode that prints, per row: purchase ID, detail ID, old `unit_price`, new `unit_price`, and confirms recomputing `sub_total` from the new `unit_price` reproduces the existing stored `sub_total` within tolerance — flag and skip (don't write) any row that fails this reproduction check rather than guessing.
- [x] 5.3 Update only `unit_price` and `price` columns (via `DB::table('purchase_details')->update()` or `forceFill()->save()` after confirming no model event reacts to this column change) inside a transaction; never touch `sub_total`, `sub_total_before_tax`, `product_tax_amount`, `total_amount`, `due_amount`, payments, stock, or product costing fields.
- [x] 5.4 Run the command with `--dry-run` against the local MySQL copy (docker `mysql-local`, `tiga_saudara` database) via tinker/artisan and manually compare its output against the 41-row list already identified (purchase IDs 18818, 18822, 18823, 18825–18828, 18834, 18837–18839, 18841, 18843, 18844, 18848, 18849, 18854–18856, 18862, 18880, 18889, 18890, 18897, 18898, 18900, 18903, 18908, 18909).
- [x] 5.5 Run the command for real (without `--dry-run`) against the same local MySQL copy first; re-run the detection query afterward and confirm zero remaining mismatches among the 41 rows.
- [x] 5.6 Spot-check purchase #18909 / detail #37661 specifically end-to-end (the originally reported case): confirm the purchase detail page now shows Rp270.000 as the unit price and the row/document totals are unchanged from before the backfill.
