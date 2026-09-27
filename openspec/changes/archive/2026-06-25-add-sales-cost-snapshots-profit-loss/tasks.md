## 1. Schema and Models

- [x] 1.1 Add a nullable `sale_details` migration for `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` with SQLite-compatible column definitions.
- [x] 1.2 Update `Modules\Sale\Entities\SaleDetails` casts/fillable behavior for the cost snapshot fields.
- [x] 1.3 Verify `Modules\SalesReturn\Entities\SaleReturnDetail` already exposes `sale_detail_id` for return cost reversal and add only minimal relationship/cast adjustments if needed.

## 2. Cost Calculation Services

- [x] 2.1 Create a shared sales cost snapshot service that resolves setting-local `product_prices.average_purchase_price`, handles non-stock-managed zero cost, calculates row totals with decimal quantity, and writes snapshot metadata.
- [x] 2.2 Create a purchase DPP cost helper that calculates unit cost from `sub_total - product_tax_amount - product_discount_amount` divided by quantity.
- [x] 2.3 Create a product average synchronization service that updates or creates `product_prices` rows for every setting and keeps `average_purchase_price` identical for a product.
- [x] 2.4 Add focused unit coverage for DPP cost calculation, global average synchronization, missing price zero-cost fallback, and decimal quantity rounding.

## 3. Future Purchase Average Updates

- [x] 3.1 Update purchase receiving approval flow to use the global product average synchronization service when received stock changes average purchase price.
- [x] 3.2 Update purchase import/normalization paths that write `average_purchase_price` so they preserve the global-per-product invariant across all settings.
- [x] 3.3 Add feature coverage proving future purchase approval/import updates all relevant `product_prices` setting rows and creates missing rows.

## 4. Future Sale Snapshot Write Paths

- [x] 4.1 Update standard sale creation/update posting paths to snapshot product cost for stock-managed sale details.
- [x] 4.2 Update POS checkout/finalization sale detail creation paths to snapshot product cost for stock-managed sale details.
- [x] 4.3 Ensure non-stock-managed standard sale and POS sale details receive zero cost with source metadata.
- [x] 4.4 Add feature coverage for live standard sale, POS sale, missing average fallback, non-stock zero cost, and sale edit quantity/product/date recalculation behavior.

## 5. Historical Backfill Command

- [x] 5.1 Implement an Artisan command for sales cost snapshot backfill with dry-run default, `--write`, `--force`, and filters for product, setting, and date ranges.
- [x] 5.2 Build the backfill replay to order purchase, receiving, purchase return, sale, and sale return events by effective transaction date rather than import/created order.
- [x] 5.3 Calculate stock-managed sale detail snapshots from cumulative tax-exclusive purchase DPP average up to sale date.
- [x] 5.4 Implement earliest-future-purchase fallback and zero-cost fallback with explicit warning categories.
- [x] 5.5 Implement non-stock-managed zero-cost handling with source metadata and summary counts.
- [x] 5.6 Implement purchase return replay effects on running quantity/value, using original cost when resolvable or running average fallback.
- [x] 5.7 Report dry-run/write summaries for scanned, fillable, updated, unchanged, skipped, missing product price rows, duplicate product identity, negative stock, archived skipped documents, and warning counts.
- [x] 5.8 Add command tests for dry-run no writes, write fills nulls only, force recomputes, idempotent reruns, future purchase fallback, no purchase fallback, negative stock warning, duplicate code warning, and archived/rejected document exclusion.

## 6. Profit/Loss Report Calculation

- [x] 6.1 Refactor `OperationalProfitLossReportService` and value object to replace purchase-based total cost with sales cost from sale detail snapshots and return-cost reversal.
- [x] 6.2 Calculate net sales from scoped completed sales and completed sale returns using the existing selected setting and date range behavior.
- [x] 6.3 Calculate sales cost from scoped sale details by sale date and subtract sale return cost by return date from original sale detail snapshots.
- [x] 6.4 Preserve approved non-archived expenses in the selected scope and date range.
- [x] 6.5 Update report labels to use `Beban Pokok Pendapatan` or equivalent sales-cost wording and remove direct purchase/purchase return cost rows.
- [x] 6.6 Update `ProfitLossReportExport` to consume the same report object and export matching sales-cost rows and totals.

## 7. Profit/Loss Report Testing

- [x] 7.1 Remove obsolete tests expecting `OperationalProfitLossReportService` to aggregate purchases.
- [x] 7.2 Add focused report service tests for sales cost snapshots, return cost reversal, expenses, selected settings, all settings, and purchase rows no longer affecting profit/loss directly.
- [x] 7.3 Add Livewire tests proving screen rows and totals match the new formula and still enforce `reports.access`.
- [x] 7.4 Add Excel export parity tests proving exported labels and totals match the screen for selected settings and all settings.

## 8. Final Validation

- [x] 8.1 Run focused tests for sales cost snapshots, purchase average synchronization, backfill command, POS/standard sale write paths, and profit/loss report.
- [x] 8.2 Run a broader PHP test pass appropriate for the touched modules, preferring focused `php artisan test` filters first and `composer test:fresh-sqlite` if risk warrants it.
- [x] 8.3 Review `git diff` for unrelated changes and confirm OpenSpec artifacts, migrations, services, UI, export, and tests align with the proposal.
