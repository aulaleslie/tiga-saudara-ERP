# Global Payment Settlement Reads — Benchmark & Differential Verification

## 1. Benchmark Methodology & Harness Protocol

All matched pre-edit and post-edit measurements are recorded from identical operation boundaries:

- **Harness script**: scratch matched CLI runner (`scratch/benchmark_harness.php`), executed cleanly before production code edits (`--phase=pre`) and after all code changes (`--phase=post`), alongside the repository PHPUnit benchmark suites (`PosGlobalPaymentFilterBenchmarkTest`, `GlobalSaleSummaryBenchmarkTest`).
- **Frozen date**: `2026-11-14 12:00:00` (`Carbon::setTestNow`) so 30-day windows and overdue cutoffs stay deterministic across pre-edit and post-edit runs.
- **Sampling**: `1 warmup + 3 measured samples` per scenario, reporting the median for SQL query count (`SQL#`), total SQL execution time (`SQL ms`), full PHP + SQL operation elapsed wall time (`Elap ms`), memory delta (`dMemMB`), process peak memory (`PkMemMB`), legacy `PosSettlementProjectionService::$projectionCount` (`Proj`), and hydrated Eloquent model counts (`PosTx`, `Sales`, `Pays`).
- **Page size**: `perPage = 10` (default on `GlobalPosPaymentTable` and `SaleTable`).
- **SQLite fixture sizes**:
  - `small`: 12 POS transactions (`10` ordinary / `2` exceptional: `16.7%`) and 20 standalone Sales, with populated `sale_payments`, `sale_payment_credit_applications`, `pos_owner_payment_transfers`, `product_unit_conversions`, `product_serial_numbers`, `sales_order_serial_tracking`, and `dispatch_details` JSON serials.
  - `large`: 120 POS transactions (`96` ordinary / `24` exceptional: `20.0%`) and 200 standalone Sales using the same mixed exceptional and ledger profiles.
- **Local MySQL read-only measurements**:
  - Executed against local MySQL 8.0.46 (`tiga_saudara`) strictly under `SET SESSION TRANSACTION READ ONLY` and rolled back cleanly with zero schema changes or fixture writes.
  - Note: Local CLI PHP/MySQL timings cannot be extrapolated as production latency (different server hardware, warm InnoDB buffer pool, network RTT = 0 ms localhost, and single-user concurrency). Exploratory single-probe numbers are excluded in favor of the matched harness runs below.

---

## 2. Dataset Proportions & Preserved Source/Spec Semantic Differences

### Local MySQL (`tiga_saudara`) Dataset Snapshot

| Table / Population | Row Count | Notes |
| --- | ---: | --- |
| `pos_transactions` | 11,597 | 11,286 eligible (`status = 'COMPLETED'`, `completedCheckout POSTED`, reachable sale) |
| `pos_transactions` (ordinary vs exceptional) | 11,115 / 171 | **1.52% exceptional** (2 batches on chunk size 200 in fallback stage) |
| `pos_checkouts` | 11,314 | `completed_checkout_id` has 0 mismatches on eligible rows; 1 tx (`pt_id = 10828`) has 2 historical checkout rows |
| `pos_checkout_sales` | 12,864 | Split mappings |
| `pos_returns` | 176 | Completed/non-reversed returns require checkout/transaction association |
| `sales` | 215,739 | `SaleTable` & `GlobalSaleSummaryQuery` active eligible scan ~212,744–215,739 rows |
| `sale_payments` | 209,267 | Active payments ledger |
| `sale_payment_credit_applications` | 0 | Active credit ledger rows (schema joined via `sale_payment_id` + `sale_payments.status = 'ACTIVE'`) |
| `pos_owner_payment_transfers` | 2 | Transfer rows (`replacement_sale_id` / `source_sale_id`) |
| `sale_return_adjustments` | 0 | Debt reduction rows |
| `dispatch_details` | 433,371 | 1,375 rows with non-empty `serial_numbers` JSON |
| `sale_details` | 431,979 | Line items |

Narrowed local MySQL slices used in the matched harness:
- **Sales narrowed** (`customer_id = 11`, `setting_id = 6`, date range `2026-01-01`..`2026-11-14`): 152,206 active Sales.
- **POS narrowed** (`customer_id = 11`, `setting_id = 1`, date range `2026-01-01`..`2026-11-14`): 8,254 completed POS transactions (`customer_id = 11, setting_id = 6` has 0 POS rows).

### Preserved Pre-Existing Semantic Distinctions

All optimizations intentionally preserve pre-existing caller semantics rather than altering financial filters or visibility:

1. **Card membership vs Table financial filter (`UNPAID` / `PARTIAL` status gate)**:
   - `SaleTable` (`dueAmountOnly` / `overdueOnly`) additionally constrains `whereIn('sales.payment_status', ['UNPAID', 'PARTIAL'])` alongside live due `> 0`.
   - `GlobalSaleSummaryQuery` computes `outstanding` / `overdue` membership purely from canonical live due `> 0` across `globalPaymentEligible()` statuses.
2. **Recent-payment upper date bound**:
   - `SaleTable` (`paidLast30DaysOnly`) checks `sale_payments.date >= today - 30 days` with no upper date ceiling.
   - `GlobalSaleSummaryQuery::recentCollections()` bounds active payments to `date >= today - 30 days` **and** `date <= today->endOfDay()`.
3. **POS child `Sale` vs standalone `Sale` debt-reduction handling**:
   - `PosSettlementProjectionService` computes child `Sale` effective paid/due from active payments, credits, and active transfers without subtracting `sale_return_adjustments.debt_reduction`, whereas `Sale::canonicalLiveDueFormula()` subtracts `sale_return_adjustments.debt_reduction`.

---

## 3. Consistent Read Boundary (`App\Support\ConsistentReadSnapshot`)

Multi-stage POS reads and Sales financial/summary reads run inside `App\Support\ConsistentReadSnapshot::run(Closure $callback, ConnectionInterface|string|null $connection = null)`:

- **Caller-owned enclosing transaction (`transactionLevel() > 0`)**: runs `$callback($conn)` directly without changing isolation level or calling `beginTransaction()`, `commit()`, or `rollBack()`. This preserves test suites wrapped in `RefreshDatabase` transactions and caller write transactions.
- **New transaction (`transactionLevel() === 0`)**:
  - **MySQL**: executes transaction-scoped `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` on PDO for the next transaction only (leaving `SESSION`/`GLOBAL` isolation untouched), opens a transaction (`beginTransaction()`), commits on success, and rolls back cleanly in `catch (Throwable)` on any error before rethrowing. This ensures Stage 1 candidate classification, Stage 2 bounded ordinary aggregation, Stage 3 fallback batches, and window-count / fallback pagination observe one consistent MVCC snapshot even under concurrent checkout or payment writes, assuming InnoDB `REPEATABLE READ` snapshot semantics.
  - **SQLite**: opens a `DEFERRED` transaction for the snapshot scope (snapshot pinned on first read under WAL mode) and commits/rolls back on exit without issuing MySQL `SET TRANSACTION` DDL.
- **Verification (`php artisan test tests/Unit/Support/ConsistentReadSnapshotTest.php`)**: 4 passed (20 assertions) covering snapshot isolation across concurrent writes, subsequent fresh reads, enclosing transaction preservation, rollback cleanup on failure, and MySQL transaction-scoped `REPEATABLE READ` SQL ordering.

---

## 4. Matched Pre-Edit Baselines

### 4.1 Repository PHPUnit Benchmark Suites (Pre-Edit)

| Command | Passed | Key Pre-Edit Metrics |
| --- | --- | --- |
| `php artisan test Modules/Pos/Tests/Feature/PosGlobalPaymentFilterBenchmarkTest.php` | 2 passed (94 assertions) | 50 records (35 ordinary, 15 split/replacement): `table_no_filter`: 62 SQL / 3.28 ms SQL / 28.53 ms elap / 10 proj / 0.00 MB delta; `table_payment_status`: 71 SQL / 4.70 ms SQL / 49.17 ms elap / 50 proj; `table_card_selection`: 72 SQL / 4.47 ms SQL / 44.79 ms elap / 50 proj; `summary_default`: 0 SQL / 1.90 ms (`PosSummaryCards::loadSummary` deferred) |
| `php artisan test Modules/Sale/Tests/Feature/GlobalSaleSummaryBenchmarkTest.php` | 1 passed (2 assertions) | size=10 summary: 2 SQL / 1.10 ms SQL / 2.31 ms elap / 60.60 MB peak; size=100 summary: 2 SQL / 1.49 ms SQL / 2.69 ms elap / 60.85 MB peak; selection: 0 SQL |

### 4.2 SQLite Matched Harness — `small (12 pos / 20 sale)` (Pre-Edit)

| Scenario | SQL# | SQL (ms) | Elap (ms) | dMem (MB) | PkMem (MB) | Proj | PosTx | Sales | Pays |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `pos_normal_p1` | 35 | 1.89 | 9.61 | 0.00 | 56.50 | 10 | 10 | 20 | 19 |
| `pos_filtered_overdue_p1` | 50 | 2.64 | 12.36 | 0.00 | 56.50 | 12 | 14 | 28 | 23 |
| `pos_summary_cards` | 23 | 1.32 | 8.18 | 0.00 | 56.50 | 12 | 12 | 24 | 23 |
| `sale_search_multi_token` | 1 | 0.91 | 2.56 | 0.00 | 58.50 | 0 | 0 | 0 | 0 |
| `sale_search_barcode_conv` | 1 | 0.61 | 1.68 | 0.00 | 58.50 | 0 | 0 | 0 | 0 |
| `sale_search_serial_dispatch` | 1 | 0.64 | 1.69 | 0.00 | 58.50 | 0 | 0 | 0 | 0 |
| `sale_search_no_match` | 1 | 0.65 | 1.67 | 0.00 | 58.50 | 0 | 0 | 0 | 0 |
| `sale_normal_p1` | 15 | 1.02 | 3.61 | 0.00 | 58.50 | 0 | 1 | 10 | 0 |
| `sale_page_unpaid_p1` | 16 | 1.20 | 3.71 | 0.00 | 58.50 | 0 | 5 | 10 | 0 |
| `sale_page_unpaid_p999_oor` | 3 | 0.48 | 1.12 | 0.00 | 58.50 | 0 | 0 | 0 | 0 |
| `sale_page_recent30d_p1` | 16 | 1.44 | 4.40 | 0.00 | 58.50 | 0 | 6 | 8 | 0 |
| `sale_summary_cards` | 2 | 0.65 | 1.70 | 0.00 | 58.50 | 0 | 0 | 1 | 1 |

### 4.3 SQLite Matched Harness — `large (120 pos / 200 sale)` (Pre-Edit)

| Scenario | SQL# | SQL (ms) | Elap (ms) | dMem (MB) | PkMem (MB) | Proj | PosTx | Sales | Pays |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `pos_normal_p1` | 35 | 2.26 | 10.10 | 0.00 | 58.50 | 10 | 10 | 19 | 19 |
| `pos_filtered_overdue_p1` | 50 | 5.45 | 58.10 | 0.00 | 60.50 | 120 | 130 | 260 | 232 |
| `pos_summary_cards` | 23 | 3.78 | 50.51 | 0.00 | 60.50 | 120 | 120 | 240 | 232 |
| `sale_search_multi_token` | 1 | 2.36 | 4.10 | 0.00 | 60.50 | 0 | 0 | 0 | 0 |
| `sale_search_barcode_conv` | 1 | 1.65 | 2.83 | 0.00 | 60.50 | 0 | 0 | 0 | 0 |
| `sale_search_serial_dispatch` | 1 | 1.89 | 3.05 | 0.00 | 60.50 | 0 | 0 | 0 | 0 |
| `sale_search_no_match` | 1 | 1.89 | 3.03 | 0.00 | 60.50 | 0 | 0 | 0 | 0 |
| `sale_normal_p1` | 15 | 1.21 | 3.89 | 0.00 | 60.50 | 0 | 6 | 10 | 0 |
| `sale_page_unpaid_p1` | 15 | 2.17 | 4.81 | 0.00 | 60.50 | 0 | 8 | 10 | 0 |
| `sale_page_unpaid_p999_oor` | 3 | 1.97 | 2.69 | 0.00 | 60.50 | 0 | 0 | 0 | 0 |
| `sale_page_recent30d_p1` | 13 | 2.48 | 4.94 | 0.00 | 60.50 | 0 | 0 | 10 | 0 |
| `sale_summary_cards` | 2 | 1.83 | 2.87 | 0.00 | 60.50 | 0 | 0 | 1 | 1 |

### 4.4 Local MySQL 8.0.46 Read-Only Matched Harness (Pre-Edit)

| Scenario | SQL# | SQL (ms) | Elap (ms) | dMem (MB) | PkMem (MB) | Proj | PosTx | Sales | Pays |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `mysql_pos_normal_p1` | 32 | 84.22 | 93.38 | 0.00 | 36.00 | 10 | 10 | 21 | 21 |
| `mysql_pos_filtered_overdue_p1` | 61 | 989.53 | 6,491.61 | 6.00 | 480.00 | 11,286 | 11,296 | 24,177 | 24,254 |
| `mysql_pos_filtered_narrowed_p1` (`11/1`) | 61 | 788.34 | 4,560.63 | 6.00 | 352.00 | 8,254 | 8,263 | 17,249 | 17,292 |
| `mysql_pos_summary_default` | 32 | 1,027.95 | 6,797.63 | 0.00 | 484.00 | 11,286 | 11,286 | 24,154 | 24,251 |
| `mysql_pos_summary_narrowed` (`11/1`) | 32 | 858.02 | 4,659.27 | 4.00 | 356.00 | 8,254 | 8,254 | 17,229 | 17,289 |
| `mysql_sale_search_broad_multi` (`CASH SERVICE`, 4,463 matches) | 2 | 7,158.75 | 7,162.23 | — | 458.00 | 0 | 0 | 0 | 0 |
| `mysql_sale_search_selective_multi` (`PD-JL-2026-11-01385 SKU-F9E81A74`, 1 match) | 2 | 6,939.73 | 6,941.80 | — | 456.00 | 0 | 0 | 0 | 0 |
| `mysql_sale_search_no_match` (`__NO_MATCH_99999__`, 0 matches) | 2 | 6,771.02 | 6,772.48 | — | 456.00 | 0 | 0 | 0 | 0 |
| `mysql_sale_page_unpaid_p1` | 17 | 294.49 | 299.16 | — | 456.00 | 0 | 18 | 10 | 0 |
| `mysql_sale_page_unpaid_p999_oor` | 3 | 282.46 | 283.76 | — | 454.00 | 0 | 0 | 0 | 0 |
| `mysql_sale_page_recent30d_p1` | 17 | 4,253.15 | 4,257.88 | — | 448.00 | 0 | 18 | 10 | 0 |
| `mysql_sale_page_narrowed_p1` (`11/6`) | 16 | 465.61 | 469.64 | — | 442.00 | 0 | 16 | 10 | 0 |
| `mysql_sale_summary_default` | 2 | 2,469.59 | 2,471.29 | — | — | 0 | 0 | 1 | 1 |
| `mysql_sale_summary_narrowed` (`11/6`) | 2 | 948.36 | 951.25 | — | — | 0 | 0 | 1 | 1 |

Oracle summary snapshots recorded on pre-edit MySQL `tiga_saudara`:
- **POS Summary Default**: `outstanding = {"count":77,"total":546505500}`, `overdue = {"count":68,"total":304898500}`, `paid_within_30_days = {"count":3356,"total":4051815980}`
- **POS Summary Narrowed** (`customer_id=11`, `setting_id=1`, `2026-01-01`..`2026-11-14`): `outstanding = {"count":9,"total":18130000}`, `overdue = {"count":8,"total":18040000}`, `paid_within_30_days = {"count":2414,"total":2121695163}`
- **Sale Summary Default**: `outstanding = {"count":117,"total":606692207}`, `overdue = {"count":102,"total":365085207}`, `recent = {"count":3938,"total":4019196251}`
- **Sale Summary Narrowed** (`customer_id=11`, `setting_id=6`, `2026-01-01`..`2026-11-14`): `outstanding = {"count":10,"total":4601061.09}`, `overdue = {"count":9,"total":4511061.09}`, `recent = {"count":2394,"total":1208114661}`

### 4.5 Baseline MySQL `EXPLAIN` Plans (Pre-Edit)

1. **POS `baseEligibleQuery` (`pos_transactions`)**:
   - Full table scan (`ALL`, `rows=11417`) on `pos_transactions` -> `eq_ref` on `pos_checkouts` via `PRIMARY` (`completed_checkout_id`) -> `DEPENDENT SUBQUERY` checks against `sales` and `pos_checkout_sales` (`key=pos_checkout_sales_checkout_split_key_unique`).
   - Subsequent table filtering/summary hydrates all ~11,286 Eloquent `PosTransaction` + 24,154 `Sale` + 24,251 `SalePayment` models in PHP memory (peaking at `484 MB` and `6.5s–6.8s`).
2. **Global Sales Search (`"CASH SERVICE"`)**:
   - `PRIMARY` scans `ALL` `sales` (`rows=212744`, `filtered=0.50%`) with `Using where`.
   - Generates **28 `DEPENDENT SUBQUERY`** blocks evaluated per candidate `sales` row across `customers`, `tags`, `pos_checkouts`, `pos_checkout_sales`, `pos_transactions`, `sale_bundle_items`, `sale_details`/`products`, `sales_order_serial_tracking`/`product_serial_numbers`, and `dispatch_details`.
3. **`GlobalSaleSummaryQuery` (2 queries)**:
   - Summary query scans `ALL` `sales` (`rows=212744`) joined against derived `<derived2>` (`agg_pay`: `sale_payments` ref `const`, `rows=104135`, `Using temporary`) and four small derived ledger tables (`sale_payment_credit_applications`, two `pos_owner_payment_transfers`, `sale_return_adjustments`), followed by a second standalone query for `recentCollections` over `sale_payments` (`209k` rows) + `sales`.
4. **`SaleTable` Financial Filters (`dueAmountOnly`, `paidLast30DaysOnly`)**:
   - `dueAmountOnly`: scans `ALL` `sales` (`rows=212744`, `Using where; Using filesort`) evaluating five correlated `DEPENDENT SUBQUERY` blocks per row (`sale_payments`, `spca`, `popt_in`, `popt_out`, `sra`), plus Laravel paginator executes a second full `COUNT(*)` query on the same filter.
   - `paidLast30DaysOnly`: scans `ALL` `sales` (`rows=212744`) with `FirstMatch(sales)` against `sale_payments` (`sale_payments_sale_status_idx`) plus the same five correlated live-due `DEPENDENT SUBQUERY` blocks (`4.26s` across 2 queries for count + page).

---

## 5. Post-Edit Implementation Verification & Matched Comparison

### 5.1 Step 3 — POS Staged Settlement Reads (`PosGlobalPaymentReadService`)

- **`PosSettlementProjectionServiceTest`**: `14 passed (129 assertions)` — ordinary scalar projection matches `PosSettlementProjectionService::project()` for credits, active/invalidated payments, active/null/inactive-backed transfers, negative net paid, overpayment, null due dates, overdue amounts, recent/future date boundaries, lifecycle/archived-returned-zero eligibility, ledger fanout non-multiplication, and non-hydration of non-visible ordinary payment-history models.
- **`PosBatchReplacementSalesResolverTest`**: `9 passed (48 assertions)` — disjoint/exhaustive ordinary vs. exceptional classification against resolver diagnostics (`single/multi-owner`, `null` customers, customer mismatch, duplicate/missing/zero `sale_id`, duplicate/null/case-sensitive `split_key`, inline mappings, checkout/transaction-linked completed returns) plus batched exceptional fallback (inline/split precedence, reversals, archived returned splits, multi-batch `200`-ID chunking, `'sales'` graph release).
- **`GlobalPosPaymentTableAndSearchTest`**: `28 passed (157 assertions)` — staged mixed ordinary + exceptional page boundaries, tied `created_at`/`id` ordering, search evaluated once per render, combined filters, ordinary unfiltered pages retaining page-only projections (`<= 10`), pure-ordinary filtered scans recording `0` fallback calls and inline-exceptional scans recording `<= 2`, immutable customer scope, table-only filter isolation, fresh `Reset`/retry replay, and preserved selection events.
- **`PosGlobalPaymentFilterBenchmarkTest`** (after production edits; 50-record fixture: 35 ordinary, 15 split/replacement):
  - Filtered paths dropped from `50` full Eloquent projections pre-edit to `15` fallback projections (`70` SQL statements vs `71–72` SQL pre-edit, keeping visible-row/ordinary pages at `10` projections on `table_no_filter`, `table_business_date`, `table_cashier_terminal`, `0` SQL on deferred `summary_default`/`summary_business_date`).
  - Full matched `scratch/benchmark_harness.php --phase=post` (SQLite small/large & read-only MySQL `tiga_saudara`) comparison including peak memory and ordinary vs. fallback projection counts will be completed in Step 6.4.

### 5.2 Step 4 — Global Sales Search Candidate Optimization (`GlobalPaymentSearchQuery::applySaleSearch`)

- **PHPUnit verification**:
  - `php artisan test Modules/Sale/Tests/Feature/GlobalSalePaymentSearchTest.php`: `15 passed (58 assertions)` — covers empty/whitespace no-op, repeated tokens, broad matches (`> 5` rows without caps), missing/no-match queries, every header/customer/tag/POS-split/bundle/current-product source, cross-field token intersections, full-input multi-word/special-char barcode and serial matches coexisting with text matches, absent catalogue products (`product_id = null`), `0` eager SQL queries during `applySaleSearch()` query building, `65` distinct-token intersection (`tok01..tok65`), duplicate provenance deduplication, outer card/business/customer/archived scoping, and non-global snapshot search.
  - Focused post-P2 SQLite suite (`13` search/snapshot/summary/table/POS/Purchase/ordering suites via `rm -f database/testing.sqlite* && php artisan test --filter=...`): **`156 passed (653 assertions)`** (`23.22s`).
  - `php artisan test Modules/Sale/Tests/Feature/EmbeddedGlobalSalePaymentIsolationTest.php`: `7 passed (18 assertions)`.
  - `php artisan test Modules/Purchase/Tests/Feature/GlobalPurchasePaymentSearchTest.php`: `8 passed (28 assertions)`.
- **Local MySQL 8.0.46 Read-Only Combined-Search Comparison (`215,739` Sales, `SQL# = 1` with zero eager serial pre-queries)**:
  - `mysql_sale_search_broad_multi` (`CASH SERVICE`, `4,463` matching Sales — exact count preserved): `7,162.23 ms` (`7,158.75 ms` SQL) -> **`1,052.24 ms`** (`1,050.65 ms` SQL), ~`6.8x` faster (`SQL# = 1`).
  - `mysql_sale_search_selective_multi` (`PD-JL-2026-11-01385 SKU-F9E81A74`, `1` matching Sale — exact match preserved): `6,941.80 ms` (`6,939.73 ms` SQL) -> **`712.38 ms`** (`711.33 ms` SQL), ~`9.7x` faster (`SQL# = 1`).
  - `mysql_sale_search_no_match` (`__NO_MATCH_99999__`, `0` matches): `6,772.48 ms` (`6,771.02 ms` SQL) -> **`462.76 ms`** (`462.02 ms` SQL), ~`14.6x` faster (`SQL# = 1`).
  - **Post-P2 MySQL `EXPLAIN` (`CASH SERVICE`)**: **`0` `DEPENDENT SUBQUERY`** blocks (`DEPENDENT_COUNT=0`, down from `28` correlated `DEPENDENT SUBQUERY` evaluations pre-edit). Outer `sales` semi-joins against materialized `<derived3>` (`type=eq_ref`, `key=<auto_distinct_key>`, `rows=1`, `Using index`) built from token-labelled `UNION` branches (`GROUP BY tok_candidates.sale_id HAVING COUNT(DISTINCT tok_candidates.token_idx) = count($tokens)` avoiding per-token `JOIN` fan-out / 61-table join limits) unioned with barcode branches and deferred SQL serial JOINs (`sost -> psn` via `product_serial_numbers_serial_number_index` `ref=const` and `sd -> psn` via `JSON_CONTAINS(sd.serial_number_ids, CAST(psn.id AS CHAR))`).

### 5.3 Step 5 — Global Sales Single-Query Summary & Financial Window Pagination (`GlobalSaleSummaryQuery` & `SaleTable`)

- **PHPUnit verification**:
  - `php artisan test Modules/Sale/Tests/Feature/GlobalSaleSummaryAggregateTest.php`: `19 passed (115 assertions)` — single-query `summarize()` executes `1` SQL query returning exact integer/float zeroed shapes on empty scopes, counts `0`-amount recent active payments on settled Sales toward `recent.count` without inflating `recent.total`, and preserves card (`<= today->endOfDay()`) vs. table (`>= today - 30d` unbounded upper date) `recent` boundaries.
  - `php artisan test Modules/Sale/Tests/Feature/GlobalSaleSummaryBenchmarkTest.php`: `1 passed (2 assertions)` — `size=10` summary `1 SQL / 0.56 ms / 1.29 ms elap`, `size=100` summary `1 SQL / 0.92 ms / 1.63 ms elap` (down from `2 SQL / 1.49 ms / 2.69 ms` pre-edit), `selection = 0 SQL`.
  - `php artisan test Modules/Sale/Tests/Feature/GlobalSalePaymentOrderingTest.php`: `2 passed (18 assertions)`.
  - `php artisan test Modules/Sale/Tests/Feature/GlobalSalePaymentTableTest.php`: `20 passed (56 assertions)`.
  - `php artisan test Modules/Sale/Tests/Feature/GlobalSalePaymentFiltersTest.php`: `39 passed (147 assertions)` — verifies `COUNT(*) OVER()` window-count ID pagination on financial pages (`0` standalone `SELECT COUNT(*)` queries on non-empty financial pages), partial last page, empty `page=1` (`0` fallback count, `0` hydration queries), out-of-range `page > 1` explicit `count()` fallback with `0` model hydrations, visible-page-only `Sale` hydration (`WHERE sales.id IN (...)` sized to `perPage`), `reporting_date` COALESCE + `id` tie-breaking in `asc`/`desc`, search intersections, and unchanged non-financial global pagination.

### 5.4 Task 6.1 — Isolated MySQL & SQLite Snapshot/Driver Verification

- **Isolated MySQL 8.0.44 verification (`./scripts/run-mysql-sequence-tests.sh tests/Feature/Mysql/GlobalPaymentSettlementReadsMysqlTest.php`)**:
  - Container parameters verified by runner before PHPUnit: MySQL `8.0.44`, `REPEATABLE-READ`, `STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`, `utf8mb4` / `utf8mb4_0900_ai_ci`, timezone `+08:00`, migrated into isolated `tiga_saudara_test` database on `127.0.0.1:33066` (leaving local `tiga_saudara` untouched).
  - Result: **`OK (5 tests, 44 assertions)`** in `19.802s` — verifies:
    1. Deferred candidate-union + token intersection (`0` eager build-time SQL queries on `applySaleSearch`) plus MySQL `HEAD` numeric-only `[id]` `JSON_CONTAINS` parity (rejecting stringified `["id"]` in both `applySaleSearch` and `applyPosTransactionSearch`).
    2. Long `65`-distinct-token intersection (`test_mysql_long_input_exceeding_61_distinct_tokens_without_join_limit`) executing cleanly on MySQL `8.0.44` without error 1116 (`MySQL can only use 61 tables in a join`).
    3. Single-query `GlobalSaleSummaryQuery::summarize()` (`1` SQL) and `COUNT(*) OVER()` `paginateFinancialSales()` (with explicit `count()` fallback only on out-of-range `page > 1`) on MySQL `8.0.44`.
    4. Two-connection (`mysql_test` + `mysql_test_writer`) `ConsistentReadSnapshot::run()` isolation on real MySQL `REPEATABLE-READ`: concurrent `pos_checkout_sales` / `pos_payments` / `sale_payments` commits written mid-snapshot do not leak between summary/list stages inside the active snapshot, subsequent reads observe the committed state, `transactionLevel()` returns to `0` afterwards, enclosing outer transactions are not prematurely committed, and thrown callbacks roll back cleanly.
    5. End-to-end `SaleTable` snapshot consistency (`test_mysql_sale_table_search_and_non_financial_reads_share_snapshot`): concurrent `$writer` serial + `SalePayment` commits do not leak into mid-snapshot `applySaleSearch` or `SaleTable` reads, and `DB::listen` confirms both financial and non-financial global `SaleTable::render()` paths execute search discovery and pagination strictly inside `ConsistentReadSnapshot` (`transactionLevel >= 1`).
- **Default SQLite verification**:
  - `php artisan test tests/Unit/Support/ConsistentReadSnapshotTest.php`: **`4 passed (20 assertions)`** (`0.43s`).
  - `php artisan test tests/Feature/Mysql/GlobalPaymentSettlementReadsMysqlTest.php`: cleanly reports `No tests found` / skipped under default SQLite config (since `@group mysql` is excluded in `phpunit.xml`).

### 5.5 Task 6.2 — Affected POS, Sales, Purchase & Root Test Suites

- **Concurrent SQLite pitfall identified and resolved**: `tests/CreatesApplication.php` routes all SQLite PHPUnit runs to the single shared file `database/testing.sqlite`. Two parallel `php artisan test` processes collided while running `migrate:fresh` (`SQLSTATE[HY000]: General error: 11 database disk image is malformed`), so `database/testing.sqlite*` was removed and all 30 affected classes were executed in a single sequential `php artisan test --filter=...` invocation (neither the full repo suite nor `composer test:fresh-sqlite` was run):
  ```bash
  rm -f database/testing.sqlite database/testing.sqlite-journal database/testing.sqlite-wal database/testing.sqlite-shm && php artisan test --filter="ConsistentReadSnapshotTest|PosSettlementProjectionServiceTest|PosBatchReplacementSalesResolverTest|PosChildSaleEligibilityServiceTest|GlobalPosMultiPaymentServiceTest|GlobalPosPaymentModelTest|GlobalPosPaymentAuthorizationTest|GlobalPosPaymentTableAndSearchTest|PosGlobalPaymentFilterBenchmarkTest|GlobalSalePaymentSearchTest|GlobalSaleSummaryAggregateTest|GlobalSaleSummaryBenchmarkTest|GlobalSalePaymentOrderingTest|GlobalSalePaymentTableTest|GlobalSalePaymentFiltersTest|EmbeddedGlobalSalePaymentIsolationTest|SaleTableLiveBalanceTest|SaleSummaryCardsTest|GlobalSalePaymentCreditExclusionTest|GlobalSalePaymentReturnedPartiallyTest|GlobalSalePaymentServiceTest|GlobalPurchasePaymentSearchTest|GlobalPaymentCardFilterRestorationTest|GlobalPaymentEffectiveDateSortTest|GlobalPaymentActionMenuAfterSearchTest|SettlementQueryCountBoundednessTest|GlobalSalesPaymentAuthorizationTest|GlobalSalesPaymentEligibilityTest|GlobalSalesPaymentAllocationTest|GlobalSalesPaymentPosKasBonTest"
  ```
- **Observed Result**: **`300 passed, 2 failed (1,330 assertions)`** in `59.38s` (`28 / 30` suites passed 100%):
  - **Passing POS suites (`8 / 8`)**: `GlobalPosPaymentAuthorizationTest` (`13/13`), `GlobalPosPaymentTableAndSearchTest` (`28/28`), `PosGlobalPaymentFilterBenchmarkTest` (`2/2`), `GlobalPosMultiPaymentServiceTest` (`14/14`), `GlobalPosPaymentModelTest` (`2/2`), `PosBatchReplacementSalesResolverTest` (`9/9`), `PosChildSaleEligibilityServiceTest` (`6/6`), `PosSettlementProjectionServiceTest` (`14/14`).
  - **Passing Sales suites (`12 / 12`)**: `EmbeddedGlobalSalePaymentIsolationTest` (`7/7`), `GlobalSalePaymentCreditExclusionTest` (`2/2`), `GlobalSalePaymentFiltersTest` (`39/39`), `GlobalSalePaymentOrderingTest` (`2/2`), `GlobalSalePaymentReturnedPartiallyTest` (`13/13`), `GlobalSalePaymentSearchTest` (`14/14`), `GlobalSalePaymentTableTest` (`20/20`), `GlobalSaleSummaryAggregateTest` (`19/19`), `GlobalSaleSummaryBenchmarkTest` (`1/1`), `SaleSummaryCardsTest` (`4/4`), `SaleTableLiveBalanceTest` (`4/4`), `GlobalSalePaymentServiceTest` (`10/10`).
  - **Passing Root & Purchase suites (`8 / 10` suites)**: `ConsistentReadSnapshotTest` (`4/4`), `GlobalPaymentActionMenuAfterSearchTest` (`5/5`), `GlobalPaymentCardFilterRestorationTest` (`6/6`), `GlobalPaymentEffectiveDateSortTest` (`2/2`), `GlobalSalesPaymentEligibilityTest` (`10/10`), `GlobalSalesPaymentPosKasBonTest` (`9/9`), `SettlementQueryCountBoundednessTest` (`2/2`), `GlobalPurchasePaymentSearchTest` (`8/8`).
- **Independently established pre-existing failures (`2` cases in untouched `GlobalSalesPayment` files)**:
  1. `Tests\Feature\GlobalSalesPayment\GlobalSalesPaymentAllocationTest` (`13 passed, 1 failed` standalone, `20.88s`): `test_valid_multi_sale_atomic_payment_across_settings` fails at `tests/Feature/GlobalSalesPayment/GlobalSalesPaymentAllocationTest.php:105` asserting `$payment->note === 'GLOBAL PAYMENT FOR CUSTOMER'` while `GlobalSalePaymentService::storeMultiPayment()` stores `'note' => $data['note'] ?? null` (`'Global payment for customer'`) and neither `SalePayment` nor `GlobalSalePaymentService` was modified by this change.
  2. `Tests\Feature\GlobalSalesPayment\GlobalSalesPaymentAuthorizationTest` (`18 passed, 1 failed` standalone, `21.26s`): `test_user_with_permission_can_view_sale_detail` returns HTTP `500` at line `227` with `Spatie\Permission\Exceptions\PermissionDoesNotExist: There is no permission named 'sales.reporting-date.override' for guard 'web'` thrown from `app/Policies/SalePolicy.php:36` (`overrideReportingDate`) while rendering `Modules/Sale/Resources/views/show.blade.php`. The test's `setUp()` does not seed `'sales.reporting-date.override'`, and neither the policy, view, nor test was modified by this change.

### 5.6 Task 6.3 — Browser Check Status (Blocked by Environment)

- **Attempted action**: Invoked the `browser` subagent (`8320a69e-78af-4d7b-ae91-84a3326118c1`) to navigate to `http://localhost:8000/sales/global-payments`.
- **Observed failure**: `chrome_devtools` (`list_pages`) failed with:
  - `Could not connect to Chrome. Check if Chrome is running and remote debugging is enabled by going to chrome://inspect/#remote-debugging.`
  - `Cause: ENOENT: no such file or directory, open '/home/leslieaula/.config/google-chrome/DevToolsActivePort'`
- **Disposition**: Per `tasks.md` §6.3 (`if browser access is unavailable, leave this task open rather than passing it through markup assertions`), Task `6.3` remains **open (`[ ]`)** in `tasks.md` and was not backfilled with Livewire/markup test proxies.

### 5.7 Task 6.4 — Final Matched Post-Edit Benchmarks, Differential Equivalence & MySQL `EXPLAIN` Plans

All matched harness timings use the frozen date `2026-11-14 12:00:00`, `perPage = 10`, and `1 warmup + 3 measured samples (median)` via `scratch/benchmark_harness.php --phase=post` (strictly read-only on local MySQL `tiga_saudara`). Local CLI PHP/MySQL timings do not include browser/network transport latency and must not be extrapolated directly to production server latency.

#### A. Repository PHPUnit Benchmark Suites (Pre vs. Post)

| Suite / Scenario | Pre-Edit | Post-Edit |
| --- | --- | --- |
| `PosGlobalPaymentFilterBenchmarkTest` (`50` tx: `35` ordinary / `15` split/replacement) | `2 passed (94 assertions)` — `table_no_filter`: `62 SQL / 10 proj`; `table_payment_status`: `71 SQL / 50 proj / 49.17 ms`; `table_card_selection`: `72 SQL / 50 proj / 44.79 ms` | `2 passed (94 assertions)` — `table_no_filter`: `10 proj`; filtered/card paths drop **`50 -> 15` projections** (`70 SQL`); `summary_default` deferred (`0 SQL`) |
| `GlobalSaleSummaryBenchmarkTest` (`size=10` & `size=100`) | `1 passed (2 assertions)` — `size=100`: `2 SQL / 1.49 ms SQL / 2.69 ms elap`, `selection = 0 SQL` | `1 passed (2 assertions)` — `size=10`: `1 SQL / 0.56 ms SQL / 1.29 ms`; `size=100`: **`1 SQL / 0.92 ms SQL / 1.63 ms elap`**, `selection = 0 SQL` |

#### B. SQLite Matched Harness — `small (12 pos / 20 sale)` (Pre vs. Post)

| Scenario | Pre SQL# / Proj / PosTx / Sales / Pays | Post SQL# / Proj / PosTx / Sales / Pays | Pre -> Post Elap (ms) | PkMem (MB) |
| --- | --- | --- | ---: | ---: |
| `pos_normal_p1` | `35` / `10` / `10` / `20` / `19` | `35` / `10` / `10` / `20` / `19` | `9.61 -> 9.27` | `56.50 -> 56.50` |
| `pos_filtered_overdue_p1` | `50` / `12` / `14` / `28` / `23` | `39` / **`2`** / `16` / `4` / `3` | `12.36 -> 6.76` | `56.50 -> 56.50` |
| `pos_summary_cards` | `23` / `12` / `12` / `24` / `23` | `25` / **`2`** / `14` / `4` / `3` | `8.18 -> 4.53` | `56.50 -> 56.50` |
| `sale_search_multi_token` | `1` / `0` | **`1`** / `0` | `2.56 -> 1.79` | `58.50 -> 56.50` |
| `sale_search_barcode_conv` | `1` / `0` | **`1`** / `0` | `1.68 -> 1.07` | `58.50 -> 56.50` |
| `sale_search_serial_dispatch` | `1` / `0` | **`1`** / `0` | `1.69 -> 1.06` | `58.50 -> 56.50` |
| `sale_search_no_match` | `1` / `0` | **`1`** / `0` | `1.67 -> 1.05` | `58.50 -> 56.50` |
| `sale_normal_p1` | `15` / `0` / `1` / `10` | `15` / `0` / `1` / `10` | `3.61 -> 2.94` | `58.50 -> 56.50` |
| `sale_page_unpaid_p1` | `16` / `0` / `5` / `10` | `16` / `0` / `5` / `10` | `3.71 -> 3.33` | `58.50 -> 56.50` |
| `sale_page_unpaid_p999_oor` | `3` / `0` / `0` / `0` | `3` / `0` / `0` / `0` | `1.12 -> 0.79` | `58.50 -> 56.50` |
| `sale_page_recent30d_p1` | `16` / `0` / `6` / `8` | `16` / `0` / `6` / `8` | `4.40 -> 3.60` | `58.50 -> 58.50` |
| `sale_summary_cards` | `2` SQL | **`1`** SQL | `1.70 -> 1.06` | `58.50 -> 56.50` |

#### C. SQLite Matched Harness — `large (120 pos / 200 sale)` (Pre vs. Post)

| Scenario | Pre SQL# / Proj / PosTx / Sales / Pays | Post SQL# / Proj / PosTx / Sales / Pays | Pre -> Post Elap (ms) | PkMem (MB) |
| --- | --- | --- | ---: | ---: |
| `pos_normal_p1` | `35` / `10` / `10` / `19` / `19` | `35` / `10` / `10` / `19` / `19` | `10.10 -> 9.26` | `58.50 -> 58.50` |
| `pos_filtered_overdue_p1` | `50` / `120` / `130` / `260` / `232` | `39` / **`16`** / `146` / `32` / `24` | `58.10 ->` **`15.33`** | `60.50 -> 58.50` |
| `pos_summary_cards` | `23` / `120` / `120` / `240` / `232` | `25` / **`16`** / `136` / `32` / `24` | `50.51 ->` **`13.36`** | `60.50 -> 58.50` |
| `sale_search_multi_token` | `1` / `0` | **`1`** / `0` | `4.10 -> 2.15` | `60.50 -> 58.50` |
| `sale_search_barcode_conv` | `1` / `0` | **`1`** / `0` | `2.83 -> 1.33` | `60.50 -> 58.50` |
| `sale_search_serial_dispatch` | `1` / `0` | **`1`** / `0` | `3.05 -> 1.28` | `60.50 -> 58.50` |
| `sale_search_no_match` | `1` / `0` | **`1`** / `0` | `3.03 -> 1.21` | `60.50 -> 58.50` |
| `sale_normal_p1` | `15` / `0` / `6` / `10` | `15` / `0` / `6` / `10` | `3.89 -> 3.32` | `60.50 -> 58.50` |
| `sale_page_unpaid_p1` | `15` / `0` / `8` / `10` | `15` / `0` / `8` / `10` | `4.81 -> 3.89` | `60.50 -> 58.50` |
| `sale_page_unpaid_p999_oor` | `3` / `0` / `0` / `0` | `3` / `0` / `0` / `0` | `2.69 -> 1.65` | `60.50 -> 58.50` |
| `sale_page_recent30d_p1` | `13` / `0` / `0` / `10` | `13` / `0` / `0` / `10` | `4.94 -> 3.42` | `60.50 -> 58.50` |
| `sale_summary_cards` | `2` SQL | **`1`** SQL | `2.87 -> 1.87` | `60.50 -> 58.50` |

#### D. Local MySQL 8.0.46 Read-Only Matched Harness (`11,286` Eligible POS / `215,739` Sales) — Pre vs. Post

| Scenario | Pre -> Post SQL# | Pre -> Post SQL (ms) | Pre -> Post Elap (ms) | Pre -> Post PkMem (MB) | Pre -> Post Proj / Hydrated (`PosTx`/`Sales`/`Pays`) |
| --- | --- | ---: | ---: | ---: | --- |
| `mysql_pos_normal_p1` | `32 -> 32` | `84.22 -> 75.96` | `93.38 -> 84.47` | `36.00 -> 32.00` | `10 (10/21/21) -> 10 (10/21/21)` |
| `mysql_pos_filtered_overdue_p1` | `61 -> 81` | `989.53 -> 289.75` | `6,491.61 ->` **`849.16`** (`7.6x`) | `480.00 ->` **`92.00`** | `11,286 (11,296/24,177/24,254) ->` **`170`** `(11,466/377/389)` |
| `mysql_pos_filtered_narrowed_p1` (`11/1`) | `61 -> 72` | `788.34 -> 222.53` | `4,560.63 ->` **`631.20`** (`7.2x`) | `352.00 ->` **`90.00`** | `8,254 (8,263/17,249/17,292) ->` **`92`** `(8,355/195/214)` |
| `mysql_pos_summary_default` | `32 -> 67` | `1,027.95 -> 284.68` | `6,797.63 ->` **`862.46`** (`7.9x`) | `484.00 ->` **`90.00`** | `11,286 (11,286/24,154/24,251) ->` **`170`** `(11,456/377/389)` |
| `mysql_pos_summary_narrowed` (`11/1`) | `32 -> 58` | `858.02 -> 218.40` | `4,659.27 ->` **`618.46`** (`7.5x`) | `356.00 ->` **`92.00`** | `8,254 (8,254/17,229/17,289) ->` **`92`** `(8,346/195/214)` |
| `mysql_sale_search_broad_multi` (`4,463`) | **`2 -> 1`** | `7,158.75 -> 1,050.65` | `7,162.23 ->` **`1,052.24`** (`6.8x`) | `458.00 -> 86.00` | `0 / 0 / 0 -> 0 / 0 / 0` |
| `mysql_sale_search_selective_multi` (`1`) | **`2 -> 1`** | `6,939.73 -> 711.33` | `6,941.80 ->` **`712.38`** (`9.7x`) | `456.00 -> 86.00` | `0 / 0 / 0 -> 0 / 0 / 0` |
| `mysql_sale_search_no_match` (`0`) | **`2 -> 1`** | `6,771.02 -> 462.02` | `6,772.48 ->` **`462.76`** (`14.6x`) | `456.00 -> 86.00` | `0 / 0 / 0 -> 0 / 0 / 0` |
| `mysql_sale_page_unpaid_p1` | `17 -> 17` | `294.49 -> 105.81` | `299.16 ->` **`109.91`** (`2.7x`) | `456.00 -> 86.00` | `0 / 18 / 10 / 0 -> 0 / 18 / 10 / 0` |
| `mysql_sale_page_unpaid_p999_oor` | `3 -> 3` | `282.46 -> 196.93` | `283.76 ->` **`198.00`** (`1.4x`) | `454.00 -> 86.00` | `0 / 0 / 0 -> 0 / 0 / 0` |
| `mysql_sale_page_recent30d_p1` | `17 -> 17` | `4,253.15 -> 622.03` | `4,257.88 ->` **`626.43`** (`6.8x`) | `448.00 -> 86.00` | `0 / 18 / 10 / 0 -> 0 / 18 / 10 / 0` |
| `mysql_sale_page_narrowed_p1` (`11/6`) | `16 -> 16` | `465.61 -> 189.36` | `469.64 ->` **`193.28`** (`2.4x`) | `442.00 -> 86.00` | `0 / 16 / 10 / 0 -> 0 / 16 / 10 / 0` |
| `mysql_sale_summary_default` | **`2 -> 1`** | `2,469.59 -> 1,751.24` | `2,471.29 -> 1,752.23` (`1.4x`) | `— -> 86.00` | `0 / 0 / 1 / 1 -> 0 / 0 / 1 / 0` |
| `mysql_sale_summary_narrowed` (`11/6`) | **`2 -> 1`** | `948.36 -> 447.29` | `951.25 ->` **`448.67`** (`2.1x`) | `— -> 86.00` | `0 / 0 / 1 / 1 -> 0 / 0 / 1 / 0` |

- **Exception ratio & residual fallback cost (local MySQL `tiga_saudara`, `11,286` eligible POS rows)**:
  - **Ordinary vs. exceptional split**: **`11,116` ordinary (`98.49%`)** / **`170` exceptional (`1.51%`)**, processed as `1` chunk (`FALLBACK_BATCH_SIZE = 200`). Peak memory in filtered/summary POS operations drops **`480–484 MB -> 90–92 MB` (~`5.3x` reduction)** and `Sale`/`SalePayment` model hydrations drop **`24,154 / 24,251 -> 377 / 389` (~`98.4%` reduction)**.
  - **Stage timing breakdown across all `11,286` eligible rows**: Stage 1 classification `8 SQL / ~390–496 ms`, Stage 2 ordinary SQL aggregation `28 SQL / ~386–404 ms`, Stage 3 exceptional fallback (`170` rows across `1` chunk of `<= 200`) **`31 SQL / ~94–123 ms`**. Note that `mysql_pos_normal_p1` (default unfiltered page) improved slightly (`93.38 ms -> 88.44 ms`, `32` SQL, `10` projections), and small/default non-financial SQLite pages remain unchanged (`~3.5–11.6 ms`, `0` projection/count overhead). Note: in `BenchmarkHarness`, filtered POS `SQL#` includes Stage 1+2+3 batch queries (`67–81` smaller bounded SQL queries total, vs `32–61` pre-edit queries that hydrated `24k+` Eloquent rows), while wall time drops `7.5x–8.1x`.

#### E. Full Population Differential Equivalence (`tiga_saudara`, Frozen `2026-11-14 12:00:00`)

- **POS (`11,286` base-eligible transactions vs. legacy `PosSettlementProjectionService::project()` oracle)**:
  - **`0` projection field mismatches** across all `11,286` transactions comparing `settlement_status`, `effective_total`, `effective_paid`, `live_due`, `effective_due_date`, `is_overdue`, `overdue_amount`, `is_payable`, `recent_payment_30d`, `has_recent_payment_30d`, `total_payment_count`, `active_payment_count`, `invalidated_payment_count`, `payment_health`, `payable_sales_count`, `returned_sales_count`, `mapping_issue`, `mapping_issue_label`, and `has_mapping_anomaly`.
  - **Exact POS Summary Parity**:
    - Default: `outstanding = {"count":77,"total":546505500}`, `overdue = {"count":68,"total":304898500}`, `paid_within_30_days = {"count":3356,"total":4051815980}` (`EXACT` vs. oracle & Section 4.4 pre-edit snapshot).
    - Narrowed (`c=11, s=1, 2026-01-01..2026-11-14`): `{"count":9,"total":18130000}`, `{"count":8,"total":18040000}`, `{"count":2414,"total":2121695163}` (`EXACT` vs. Section 4.4).
- **Sales Summary Cards (`GlobalSaleSummaryQuery::summarize()`)**:
  - Default (`1` SQL query vs. `2` pre-edit): `{"outstanding":{"count":117,"total":606692207},"overdue":{"count":102,"total":365085207},"recent":{"count":3938,"total":4019196251}}` (`EXACT` vs. Section 4.4).
  - Narrowed (`c=11, s=6, 2026-01-01..2026-11-14`, `1` SQL query vs. `2` pre-edit): `{"outstanding":{"count":10,"total":4601061.09},"overdue":{"count":9,"total":4511061.09},"recent":{"count":2394,"total":1208114661}}` (`EXACT` vs. Section 4.4).
- **Sales Complete Matching ID Sets, Canonical Ordering (`COALESCE(reporting_date, date) DESC, id DESC`), Page Totals & Empty-Fallback Checks (`215,739` Sales)**:
  - `unpaid`: `count = 117 | oracle_id_match = EXACT | p1_standalone_count_sql = 0 | p1_total_match = EXACT (16,170,000.00) | oor_total = 117, oor_items = 0`.
  - `overdue`: `count = 102 | oracle_id_match = EXACT | p1_standalone_count_sql = 0 | p1_total_match = EXACT (27,665,000.00) | oor_total = 102, oor_items = 0`.
  - `recent30d`: `count = 3,938 | oracle_id_match = EXACT | p1_standalone_count_sql = 0 | p1_total_match = EXACT (6,163,003.00) | oor_total = 3,938, oor_items = 0`.
- **Sales Search Complete Matching ID Set Parity (`GlobalPaymentSearchQuery::applySaleSearch()` vs. pre-edit OR oracle)**:
  - `'CASH SERVICE'`: `post = 4,463 | oracle = 4,463 | exact_set_match = EXACT`.
  - `'PD-JL-2026-11-01385 SKU-F9E81A74'`: `post = 1 | oracle = 1 | exact_set_match = EXACT`.
  - `'__NO_MATCH_99999__'`: `post = 0 | oracle = 0 | exact_set_match = EXACT`.

#### F. Live Post-Edit MySQL `EXPLAIN` Summary

1. **POS Stage 1 & Stage 2 (`PosGlobalPaymentReadService`)**:
   - Stage 1 header/checkout classification scans `pos_transactions` (`index`, `PRIMARY`, `rows=11417`) -> `eq_ref` `pos_checkouts` (`PRIMARY`) -> `eq_ref` `pc` (`PRIMARY`).
   - Stage 2 ordinary settlement ledger queries are key-range bounded per ordinary Sale batch (e.g., `sale_payments`: `type=range`, `key=sale_payments_sale_status_idx`, `rows=2000`, `Extra=Using index condition`).
2. **Global Sales Search (`"CASH SERVICE"`)**:
   - **`0` `DEPENDENT SUBQUERY`** blocks (`DEPENDENT_COUNT=0`, down from `28` pre-edit). Outer `sales` semi-joins materialized `<derived3>` (`type=eq_ref`, `key=<auto_distinct_key>`, `rows=1`, `Using index`) built from grouped token-labelled `<derived4>` (`id=3`, `select_type=DERIVED`, `Using filesort`; `<union4..17>` unioning the 7 token branches per token) unioned with exact barcode branches and deferred SQL serial JOINs (`sost -> psn` via `product_serial_numbers_serial_number_index` `ref=const` and `sd -> psn` via numeric-only `JSON_CONTAINS(sd.serial_number_ids, CAST(psn.id AS CHAR))`) with **`0` eager serial pre-lookup queries**.
3. **Global Sales Single-Query Summary (`GlobalSaleSummaryQuery::summarize`)**:
   - Single query scans `sales` (`type=range`, `key=sales_status_index`, `rows=106422`, `Extra=Using index condition; Using where`) left-joined to `<derived2>` (`sale_payments` grouped by `sale_id`, `key=sale_payments_status_idx`, `rows=104135`, computing both lifetime active paid and 30-day `recent_amount_sum` / `has_recent_payment`) plus four small derived ledger tables (`spca`, 2× `popt`, `sra`), eliminating the second full `sale_payments` summary pass.
4. **Global Sales Financial Pagination (`paginateFinancialSales`, `COUNT(*) OVER()`)**:
   - `unpaid p1`: single `SELECT ... COUNT(*) OVER()` query (`filesort` on matching due rows) with `0` duplicate standalone `SELECT COUNT(*)` queries.
   - `recent30d p1`: drives from grouped `<derived2>` (`sale_payments` `sale_payments_status_idx`, `rows=104135`, `HAVING MAX(CASE WHEN date >= ? THEN 1 ELSE 0 END) = 1`) joined via `eq_ref` `sales` (`PRIMARY`, `rows=1`) with `COUNT(*) OVER()` (`0` standalone `SELECT COUNT(*)` queries; `4,257.88 ms -> 611.44 ms`, `~7.0x` speedup).
