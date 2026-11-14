# Design

## Context

See proposal.md for the problem and scope. The first phase is archived at `openspec/changes/archive/2026-11-14-quicken-global-payment-lists/`. Current financial-read entry points are `app/Livewire/Pos/GlobalPosPaymentTable.php`, `Modules/Pos/Services/PosSettlementProjectionService.php`, `Modules/Sale/Services/GlobalSaleSummaryQuery.php`, `app/Livewire/Sale/SaleTable.php`, and `app/Support/GlobalPaymentSearchQuery.php`.

The exploration used read-only local transactions, application query/model classes without the HTTP lifecycle, and a frozen 2026-11-14 application date. These are exploratory samples, not reproducible implementation baselines or browser latency promises. Local MySQL is 8.0.46; the user confirmed production matches. SQLite remains the focused test driver.

| Experiment | Existing path | Alternative evidence |
|---|---|---|
| POS complete projection | About 6.1-6.5 s, about 376 MB additional model memory | Staged SQL for 11,116 transactions plus existing PHP for 170: 557 ms, about 21 MB additional memory |
| POS ordinary-column trimming | Full load about 3.05 s; projection about 3.05 s | Slim load about 2.26 s; projection still about 3.11 s, about 218 MB additional memory |
| Sales financial count plus ordered IDs | About 3.3-3.4 s | Window-count query about 1.16-1.22 s with matching IDs/totals |
| Sales all summary cards | About 2.47 s across existing reads | Shared aggregation about 1.78-2.02 s; all counts and totals matched |
| Combined Sales search | About 6.61-8.03 s | About 0.63-1.01 s; same IDs for reference, no-match, cross-field tokens, repeated tokens, barcode and lower-case serial inputs |

All 11,286 POS rows matched the legacy projection on the measured amounts, status, due date, overdue/recent flags and payability. The dataset includes 1,343 multi-Sale checkouts, but no credit applications or standard return adjustments and only two owner transfers. Read-only synthetic probes additionally checked 15 classifier cases, 16 monetary/recent-payment cases, and eight MySQL/SQLite pagination cases. They do not replace repository tests. A monolithic POS CTE performed poorly and was cancelled; the staged query shape is intentional.

## Goals / Non-Goals

**Goals:** Reduce full-graph hydration and repeated financial/search evaluation while keeping exact identity sets, ordering, totals, diagnostics, authorization and context-specific balances. Reuse existing services as the equivalence oracle and exceptional-record path.

**Non-Goals:** No persisted read model, financial result TTL cache, new database tables/indexes, search engine, Livewire upgrade, frontend loading redesign, ledger reconciliation change, or POS text-search rewrite. Normal Sales and Purchase behavior stays unchanged. This work does not unify Sales/POS formulas or fix pre-existing financial/search semantic discrepancies.

## Decisions

### 1. Use focused module read services and operation-local state

Introduce a POS financial-read coordinator (for example `PosGlobalPaymentReadService`) backed by narrowly scoped classification and aggregation queries. Reuse the existing resolver and `project()` for exceptional candidates and page presentation. Extend the Sale summary read service into a reusable scoped settlement query builder and add a Sales-payment candidate-search helper. Public integration remains through existing Livewire components and permissions. Avoid a cross-module generic query framework.

Only ledger-sum primitives may be shared where arithmetic is identical. POS and Sales final due expressions, eligibility, mapping, scope and recent-collection meaning remain context-specific. Prepared IDs, scalar results and fallback context live for one operation only, including under long-lived workers.

Alternative: materialize balances and update them on every write. Rejected for this phase because payments, invalidations, credits, transfers, returns and midnight boundaries create broad invalidation obligations; measured improvements are available with fresh reads.

### 2. Classify POS candidates conservatively before reading financial graphs

Resolve the existing base-eligible and structured/search scope into server-owned scalar transaction/checkout IDs once per table operation. Keep summary scope separate: it follows customer/business/transaction/due dates, not table-only search/status/cashier/terminal flags. For POS search use the existing predicate once to obtain candidates; do not run it again while classifying, counting and hydrating the same operation.

A transaction enters the ordinary SQL path only when it has a posted completed checkout, a nonempty split mapping, nonzero existing Sale IDs, no duplicate Sale IDs or split keys under a provably safe comparison, no checkout-customer mismatch, and no active completed return linked through checkout or transaction identity. Customer comparison must mirror PHP integer behavior, including a null Sale customer against a nonzero checkout customer. Conservative false negatives are acceptable; false positives are not.

Route inline mappings, missing/zero IDs, duplicates, uncertain null/collation key comparisons, customer mismatches and active completed returns to the existing resolver. Preserve its split-over-inline precedence, checkout/transaction return association, replacement inclusion/deduplication and diagnostics. Classify every candidate exactly once into disjoint, exhaustive sets; do not infer that all production exceptions are the 170 local return records. Archived/lifecycle-ineligible Sales still contribute their established projection and payment restrictions; do not filter them out merely because they cannot receive payment.

The synthetic classifier accepted ordinary single/multi-owner and absent-checkout-customer cases and conservatively rejected the other twelve cases. In particular, `COUNT(DISTINCT ...)` with case-insensitive collation must not silently normalize PHP-distinct split keys into a valid merged mapping.

### 3. Aggregate ordinary POS settlement and combine the fallback before pagination

For ordinary candidates, restrict payment/credit/transfer aggregation to their Sale IDs and group each one-to-many ledger independently before joining mappings. Preserve per-Sale effective-paid clamping and rounding, then per-Sale due clamping and rounding, then transaction aggregation. Use active original-payment rules for transfers; null-backed transfers affect amounts but do not supply a recent payment date. A zero-amount active payment can still qualify as recent. Due dates use the earliest date among outstanding Sales; overdue totals include outstanding overdue children, and recent POS qualification requires the transaction to be currently paid. Match the current `PosChildSaleEligibilityService` rules, including archived fully returned zero-value Sales.

Use staged, bounded ID batches and compact scalar results rather than an optimizer-sensitive monolithic CTE or unbounded model collection. Bind or integer-normalize IDs; respect SQLite parameter and MySQL packet limits. SQL summaries reduce ordinary counts/amounts without hydrating ordinary models. For filtered tables SQL returns matching ordinary IDs; exceptional projections contribute their matching IDs before total calculation, sorting and pagination. Never paginate the two groups independently. Their union gives an exact distinct total; fetch and order the visible page through the established transaction sort and ID tie-breaker. Empty matches must stay empty.

Batch exceptional records and replacement preparation; do not keep full fallback graphs after their scalar contribution is consumed. Reuse visible fallback projections where possible. Ordinary visible rows still use the existing detailed projection and presentation relations, so diagnostics/actions retain the established shape. When no projection filter is active, retain the existing page-first path; do not scan all financial candidates solely for a normal page.

Alternative: slim eager loads alone. Rejected as the main solution: they reduced memory but left roughly three seconds of projection CPU. Filtering after pagination is also rejected because it changes counts and page contents.

### 4. Resolve Sales search candidates from the source records

Keep empty/whitespace input as a no-search bypass. For each whitespace token, union Sale IDs from supported header fields, matching customers, JSON-localized tags and taggables, inline and split POS identifiers, bundle names, and current linked-product names/codes. Intersect token sets on the same Sale, using distinct token positions so repeated tokens retain their existing meaning. Use parameterized query-builder unions/joins and driver-specific JSON expressions.

Separately resolve complete trimmed-input primary/conversion barcodes and serial provenance (tracking records, dispatch JSON strings, and Sale-detail serial IDs) with the existing normalization, case variants, numeric JSON identity and linkage rules. Union those exact identity IDs with the complete text-match set, then deduplicate and intersect all caller scope. No early return on an exact identifier. Do not truncate broad candidate matches or add a new minimum input length, token limit, snapshot fallback, or search mode.

Keep relationships distinct: Sales product text uses current catalog identity; POS includes line snapshots and additional header/business/payment fields. POS finance gives split mappings precedence and appends replacements, while existing POS search traverses split/inline links under its own scopes. Do not replace POS search with the financial reachable-Sale set. Changes to `applySaleSearch` affect global payment callers only; Purchase and normal Sales search keep their behavior.

Apply customer/business/lifecycle/archive constraints during candidate discovery where possible and always on final results. Source lookup does not bypass immutable customer scope. Exact catalogue lookup must not drop valid serial matches retained only in historical dispatch JSON.

### 5. Share Sales settlement aggregation and preserve separate consumer predicates

Keep independently grouped active monetary, credit, incoming/outgoing transfer and return-debt-reduction datasets. Add recent active-payment amount and count indicators to the payment aggregate and calculate all three cards in one scoped outer aggregation. Recent membership uses existence/count, not a positive amount test, and totals remain active payment amounts over currently settled eligible Sales. Preserve raw canonical membership separately from rounded/clamped accessor totals, including the existing negative-net-transfer discrepancy. Bind application-date boundaries rather than hardcoding the exploration date.

Restrict aggregates to narrowed scopes as appropriate; the prior phase measured that forcing this restriction for the unfiltered scope was slower. Reuse the same query construction for financial table reads without forcing summaries and tables to share predicates or running a full aggregation for normal nonfinancial pages.

Known source/spec tensions are explicitly outside this performance change: the table's unpaid/overdue cards additionally use stored `payment_status` restrictions, and its recent-payment predicate currently lacks the summary's future-date upper bound. POS projection also does not separately subtract standard return-debt reductions as the Sale accessor does. Preserve current caller predicates/arithmetic and record these as separate correctness concerns; do not silently fix or broaden them while demonstrating performance equivalence. Existing permission, archive, due-date, document-date and reporting-date semantics likewise remain intact.

Alternative: trust indexed stored `due_amount` to skip historical Sales. Rejected: local stored/live membership happened to agree, but that does not establish freshness across all write paths.

### 6. Window-count financial pages, with an explicit empty-page fallback

For global Sales pages requiring financial predicates, evaluate the complete scoped candidate/settlement query once, select ordered IDs with `COUNT(*) OVER()`, and apply offset/limit only afterward. The count must describe distinct Sales before pagination; no ledger/search join can duplicate a Sale. Hydrate the returned IDs with existing page-only presentation/aggregate relations, preserving the selected order and paginator URL/page naming.

Use the count from a nonempty page to construct the established length-aware paginator. A first empty page has total zero. For an empty page at offset greater than zero, run a fallback count of the same scoped predicate so an out-of-range request retains the true positive total. Do not substitute cursor/simple pagination or reset the requested page as an optimization. Keep the current page/count strategy for nonfinancial pages unless matched evidence justifies changing it. Sorting remains `COALESCE(reporting_date,date)` with the existing ID tie-breaker and requested direction.

MySQL 8.0.46 and current SQLite support window counts; no dependency upgrade is needed. Exploration verified tied dates, reporting-date overrides, first/partial-last/out-of-range pages and empty scopes on both drivers.

### 7. Keep each multi-stage operation in one read snapshot

Candidate resolution, classification, financial aggregation, exceptional projection, count fallback and page hydration use the same connection and consistent transaction snapshot. Top-level MySQL reads use repeatable-read transaction semantics without persistent session/global isolation changes or locking all candidate rows. Preserve compatible caller-owned transactions; do not commit them or attempt unsupported nested isolation changes. SQLite uses its read-transaction snapshot. Release operation state on success and failure. Separate Livewire requests remain separate fresh reads; do not share financial results between the card/table requests.

No read path updates balances or touches receipt/checkout facts. Payment preview/submission continues through existing fresh locked services, not the optimized list read service.

### 8. Focused verification and performance acceptance

Extend existing focused tests near each implementation group rather than collecting correctness work at the end. POS coverage includes SQL/legacy equivalence, conservative classifier cases, invalid fallback outputs, inline/split/replacement/reversed/archived cases, credits/transfers/rounding/date boundaries, disjoint combination before pagination and unchanged page-first behavior. Sales coverage includes all search sources and cross-field/repeated tokens, combined text+identity matches, case/partial identity/provenance, scope isolation, ledger fanout, card meanings, exact totals and empty/tied pages. Both standalone and embedded contexts retain authorization and customer constraints.

Run focused `php artisan test` classes/methods only. Reuse `PosSettlementProjectionServiceTest`, `PosBatchReplacementSalesResolverTest`, `GlobalPosPaymentTableAndSearchTest`, `GlobalSaleSummaryAggregateTest`, `GlobalSalePaymentSearchTest`, `GlobalSalePaymentOrderingTest`, relevant table/filter/embedded tests and the existing card-restoration checks. Add focused query tests only where those classes cannot express the invariant. No full-suite or `composer test:fresh-sqlite` task is required.

During implementation create `benchmark.md` under this change. Before edits record a matched baseline using one warmup and three samples, a frozen date, fixed page/search/filter scope and database driver. Repeat afterward. Record SQL count/time, operation elapsed time, peak/delta memory and fallback/model/projection counts separately for table, summary and search. Small/large isolated fixtures must include exceptions and populated credits/return adjustments, unlike the local dataset. Use existing local MySQL only for read-only benchmark/EXPLAIN and row-level differential comparison; never seed or mutate it. MySQL-specific window/search/snapshot tests use a guarded isolated database; if unavailable, report those checks outstanding instead of substituting SQLite evidence.

Release evidence must show materially lower end-to-end server elapsed time and memory for large ordinary POS scopes, fewer ordinary model projections, faster complete Sales search, and removal of duplicate financial count evaluation on nonempty pages. The cheap unfiltered page and narrow scopes must not materially regress. Timings are evidence rather than brittle PHPUnit thresholds; rerun before explaining a surprising result. Record exception ratios and residual cost when fallback dominates.

Browser smoke checks cover default/filtered/card/search/page flows, empty and out-of-range URLs, tied sorting, embedded customer scope, initialization, Apply/Reset and failure/retry behavior inherited from phase one. An execution failure must release snapshot state and reach the existing error UI. If browser tooling is absent, leave that task open. Do not claim production latency from SQL-only or SQLite probes.

## Risks / Trade-offs

- [Classifier admits a malformed mapping] -> Conservative rules, adversarial fixtures and comparison with existing diagnostics; uncertain records use fallback.
- [Fallback graph grows with exceptional history] -> Bounded batches, release full graphs promptly and disclose exceptional ratio/cost; no claim of constant-time reads.
- [Optimizer merges a large staged query poorly] -> Keep measured stages, inspect actual plans and batch bounded IDs rather than assuming one CTE is faster.
- [SQL and PHP financial expressions drift] -> Separate context-specific expressions and differential tests for every calculated output; no collateral semantic repair.
- [Union candidates broaden scope or lose identity matches] -> Deduplicate IDs, preserve text-plus-identity union and constrain final results by the original caller scope.
- [Window total disappears on an empty offset page] -> Explicit same-predicate count fallback with cross-driver tests.
- [Long read transactions retain database versions] -> Keep work read-only, bounded and operation-local; no transaction across browser requests.
- [Earlier loading/retry regressions recur] -> Preserve the frontend coordinator and run focused regression and browser checks rather than redesigning it.

## Migration Plan

Deploy application code only after focused correctness and matched performance evidence, with browser and MySQL verification gaps explicitly recorded. No data backfill, schema/index migration or shared-cache invalidation is needed. Roll back by reverting application code; payment ledgers and historical checkout data remain untouched.
