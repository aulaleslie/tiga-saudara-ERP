# Tasks

## 1. Matched baseline

- [x] 1.1 Capture pre-change table, summary and card/filter-chain metrics with the design's matched measurement boundaries (10/100 isolated Sales, one warmup, three samples); create benchmark.md with fixture/driver/date/page-size details, SQL count/time, elapsed time and peak memory. Verify the baseline exercises current summary recalculation and per-Sale balance reads rather than component setup alone.
- [x] 1.2 Record read-only local MySQL Sales outstanding/overdue/recent-collection and default/business-date scope timings and EXPLAIN plans against the existing dataset; verify no persistent data is changed and distinguish SQL-only evidence from browser latency in benchmark.md.

## 2. Sales aggregate reads

- [x] 2.1 Implement grouped global Sales summary queries for outstanding/overdue counts and totals without model hydration or per-Sale balance queries, retaining existing recent-collection meaning. Verify focused aggregate equivalence tests cover multiple payments/credits/transfers per sale, inactive originals, null-backed transfers, committed return reductions, overpayments, rounding/clamping, archive/eligibility exclusions and inclusive date boundaries.
- [x] 2.2 Include committed debt-reduction sums in Sale read aggregates, use known zero aggregates correctly and clear the shortcut before reconciliation. Verify aggregate/fallback equivalence, no reduction query per displayed row and fresh reconciliation after loading an aggregate then changing settlement data; include one ordinary setting-scoped Sale table regression.
- [x] 2.3 Verify global Sales summary query count does not grow per matching Sale between 10/100 fixtures, and customer/business/date scope remains exact using focused SaleSummaryCardsTest and EmbeddedGlobalSalePaymentIsolationTest coverage; update benchmark.md with matched query/elapsed/memory results and any formula/accessor discrepancies.

## 3. Deferred summary calculation and selective refresh

- [x] 3.1 Defer global Sales/POS summary calculations using cheap authorized initialization and explicit load/retry actions; guard render/accessor fallbacks and preserve non-global Sales behavior. Verify initial renders perform zero summary calculations, successful loads calculate once, unauthorized deferred actions are forbidden and immutable customer constraints survive initialization.
- [x] 3.2 Retain global Sales calculated totals by normalized summary inputs; make card selection and unchanged Apply avoid recalculation while changed inputs, initialization, retry and explicit Reset remain fresh. Verify selection/deselection, default-state Reset, URL restoration and unchanged/changed Apply using focused GlobalSalePaymentFiltersTest/GlobalSalePaymentTableTest methods.
- [x] 3.3 Preserve latest applied inputs across initialization and overlapping filter requests for both workspaces; verify a filter applied before summary initialization and successive applies cannot leave obsolete totals or selection. Retain existing POS projection, filter and payment behavior with focused GlobalPosPaymentTableAndSearchTest methods.

## 4. Loading feedback and recovery

- [x] 4.1 Add separate summary/table placeholders, pending feedback and recoverable error/retry states to global Sales and POS templates, including POS summary filter-event refreshes and Sales card-to-table feedback. Verify affected component rendering/event tests cover initialization, Apply/Reset, selection, table actions and error/retry; no unknown amount is rendered as zero.
- [x] 4.2 Scope pending/completion/failure events to the active workspace, prevent stale completion from ending newer work and clean up hooks on navigation. Verify focused event tests plus a browser slow-request smoke check show each region stays pending for its own operation and unrelated failures do not clear it.
- [x] 4.3 Browser-check standalone Sales/POS and customer-embedded workspaces for slow initial summaries, Apply/Reset, search/page/sort, card toggle, rapid Apply, failed summary retry, failed table refresh and navigation cleanup. Record request ordering and visible loader results in benchmark.md; if browser execution is unavailable, mark this verification outstanding rather than substituting markup assertions.

## 5. Focused integration evidence

- [x] 5.1 Run only the affected Sale/POS test classes or focused methods with php artisan test, including embedded customer isolation and the existing POS filter/projection regressions; record exact commands and results in benchmark.md. Do not add a full-suite or composer test:fresh-sqlite run unless a specific failure warrants wider testing.
- [x] 5.2 Repeat the matched baseline and read-only MySQL measurements after implementation; record SQL versus first-paint versus complete-operation improvements, EXPLAIN changes, sampling limitations and remaining POS full-scan cost. Verify financial results are unchanged and improvements are supported by comparable measurements rather than faster placeholders alone.
