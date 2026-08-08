# Tasks

## 1. Command scaffold

- [x] 1.1 Create `Modules/Product/Console/FillAverageCostFromLastPurchasePriceCommand.php` with signature `product:fill-average-cost-from-last-purchase-price {--write : Apply the changes to the database}` and a description naming it the terminal fallback beneath the purchase-history and sales-HPP commands.
- [x] 1.2 Register the command wherever its siblings are registered in the Product module, and confirm it appears in `php artisan list product`.

## 2. Owner ladder

- [x] 2.1 Resolve Perdana, Top IT, and Tiga Nusa setting IDs by lowercased trimmed `company_name` match, mirroring `SeedAverageCostFromSalesHppCommand::getSpecialSettingIds`.
- [x] 2.2 Implement donor ranking: Perdana, then Top IT, then Tiga Nusa, then all unranked owners, with ascending `setting_id` as the final tiebreak within a rank.

## 3. Resolution

- [x] 3.1 Iterate `ProductPrice` with `chunkById(100, ...)`, grouping sibling rows by `product_id` so donor lookup does not issue a query per row.
- [x] 3.2 Treat a row as eligible when `(float) average_purchase_price` is not positive, so null and zero collapse to the same condition.
- [x] 3.3 Own-fill path: when the eligible row's own `last_purchase_price` is positive, set `average_purchase_price` from it and leave `last_purchase_price` untouched.
- [x] 3.4 Donor path: when the row's own `last_purchase_price` is not positive, select the highest-ranked donor row for the same product at a different setting with a positive `last_purchase_price`, and set both `average_purchase_price` and `last_purchase_price` on the target row to the donor value.
- [x] 3.5 Leave the row unchanged and count it unresolved when no donor exists.
- [x] 3.6 Never write to the donor row, never create a `product_prices` row, and never overwrite a positive `average_purchase_price`.

## 4. Reporting

- [x] 4.1 Track counters for considered, own-fill, donor-fill, unchanged, and unresolved, incrementing identically in dry-run and write mode.
- [x] 4.2 Emit a summary under a `DRY-RUN` or `WRITE` heading in the style of `SeedAverageCostFromSalesHppCommand::outputReport`, keeping own-fill and donor-fill as separate lines so the proportion of borrowed costs is visible.
- [x] 4.3 Apply writes only inside the `--write` branch.

## 5. Tests

- [x] 5.1 Dry run reports prospective changes and writes nothing.
- [x] 5.2 Write mode applies the same changes the dry run reported.
- [x] 5.3 Null average and zero average are both eligible and both filled.
- [x] 5.4 A positive average is left unchanged.
- [x] 5.5 Own last purchase price wins even when donors are available, and `last_purchase_price` is left untouched on that path.
- [x] 5.6 Donor fill sets both cost fields on the target row and leaves the donor row untouched.
- [x] 5.7 Donor ladder order: Perdana beats Top IT; Top IT is used when Perdana has no positive value; Tiga Nusa is used when neither does.
- [x] 5.8 Two unranked donors resolve to the lower `setting_id`, and the choice is stable across runs.
- [x] 5.9 A row with no donor anywhere is counted unresolved and left unchanged.
- [x] 5.10 A missing `product_prices` row is not created even when a donor cost exists for the product.
- [x] 5.11 Running `--write` twice reports zero fills on the second run.

## 6. Verification

- [x] 6.1 Run the focused test suite for the new command with `php artisan test --filter=FillAverageCostFromLastPurchasePrice`.
- [x] 6.2 Run the sibling commands' tests to confirm the shared bucket-resolution logic was not disturbed.
- [x] 6.3 Run the command in dry-run mode against a production-like dataset and record the four counters in the proposal's open question, which sizes the actual recovery rate.
