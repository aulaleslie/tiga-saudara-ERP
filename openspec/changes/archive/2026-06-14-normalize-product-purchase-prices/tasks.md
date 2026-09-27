## 1. Test Coverage

- [x] 1.1 Add command dry-run coverage proving no `product_prices` rows are created or updated without `--write`.
- [x] 1.2 Add normalization coverage proving approved received-note quantities are used when they exist for a purchase detail.
- [x] 1.3 Add normalization coverage proving purchase detail quantity is used when a received or partially received purchase detail has no approved received-note trail.
- [x] 1.4 Add exclusion coverage for archived purchases, draft/waiting/approved-only/rejected purchases, purchase returns, products with no eligible quantity, and non-stock-managed products.
- [x] 1.5 Add weighted-average and latest-price coverage proving tax-included `purchase_details.price` drives average and last purchase price calculations.
- [x] 1.6 Add all-settings synchronization coverage proving existing `product_prices` rows preserve sales/tier/tax fields while purchase price fields are updated.
- [x] 1.7 Add missing-row coverage proving rows are created for every setting, copy same-product non-zero sale metadata when available, default missing tiers to sale price, and default to zero/null when no template exists.

## 2. Command Implementation

- [x] 2.1 Create the artisan command with dry-run default behavior and an explicit `--write` option.
- [x] 2.2 Implement eligible purchase cost event aggregation for stock-managed products using received/partially received non-archived purchases.
- [x] 2.3 Implement approved received-note quantity precedence with purchase detail quantity fallback when no approved receipt exists.
- [x] 2.4 Implement weighted average purchase price calculation with two-decimal rounding and deterministic latest purchase price resolution.
- [x] 2.5 Implement missing `product_prices` row initialization across all settings using same-product non-zero sale template rows when available.
- [x] 2.6 Implement write-mode updates that only change `last_purchase_price` and `average_purchase_price` on existing rows and create missing rows as specified.
- [x] 2.7 Implement dry-run and write summaries showing considered products, skipped products, created rows, updated rows, and unchanged rows.

## 3. Integration And Safety

- [x] 3.1 Register the command using the project's existing console command pattern.
- [x] 3.2 Ensure the implementation uses chunking or grouped queries to avoid loading full purchase/product graphs into memory.
- [x] 3.3 Ensure the command is idempotent by rerunning write mode without producing additional changes after the first successful normalization.
- [x] 3.4 Confirm the command does not alter legacy `products` price fields, purchase documents, received notes, purchase returns, product stocks, transactions, sales prices, or tier prices on existing rows.

## 4. Verification

- [x] 4.1 Run the focused command test file or filter for product purchase price normalization.
- [x] 4.2 Run relevant existing product price, purchase import, and purchase receiving tests to guard surrounding behavior.
- [x] 4.3 Run `php artisan test` with focused filters or `composer test:fresh-sqlite` if the implementation touches shared migration/test setup.
- [x] 4.4 Execute the command in dry-run mode against local data and inspect the summary for plausible counts before considering production use.
