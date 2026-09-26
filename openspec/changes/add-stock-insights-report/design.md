# Design

## Context

See `proposal.md` for motivation. The application already stores location-level stock in four condition/tax buckets, exposes a tested cross-business stock matrix, stores one product-wide `product_stock_alert`, determines fulfilled-sale report eligibility centrally, uses effective reporting dates, and captures sale-detail and bundle-component cost snapshots. The existing Sales Return lifecycle mutates commercial SaleDetail values for the return paths relevant to this view, so a second return aggregate would risk double subtraction.

`Stok Persediaan Lintas Bisnis` remains the detailed physical-stock/serial report. `Pantauan Stok` is a separate operational report using the same hierarchy vocabulary but a narrower Good-stock primary measure and a small inline minimum configuration exception.

## Goals / Non-Goals

**Goals:**

- Keep one query/service boundary for stock, status, sales, and financial projections so cards, rows, sorting, and the modal use identical numbers.
- Make all aggregation identities explicit and testable from global to business to active location.
- Reuse established eligibility, effective-date, cost-snapshot, report-card, Livewire, and permission conventions.
- Keep report interactions read-only except for an audited, permission-checked minimum-stock update.
- Bound query count and memory usage through grouped database aggregates over the current page's product IDs.

**Non-Goals:**

- Historical stock snapshots or arbitrary end dates.
- Forecasting, purchasing recommendations, safety stock, seasonal models, ABC/FSN analysis, inventory turnover, or stock-excess classification.
- Business- or location-specific minimums, business/location filters, or uneven-distribution warnings.
- Stock adjustment, purchase, transfer, bucket correction, general product editing, export, or saved-filter behavior.
- Replacing or changing existing inventory, sales, return, or financial reports.
- Full-suite execution or automated browser verification.

## Decisions

### 1. Add a dedicated report and permission without replacing existing reports

Add a `Pantauan Stok` card to the Produk tab, backed by a new `reports.stock-insights.index` route and `stockInsights.access` permission. Register that permission with the reports landing entry gate as well as card, route, component mount, and mutation action checks. The one permission covers financial visibility and the minimum update because the requested capability is available only to explicitly authorized users.

Alternative considered: replace `Ringkasan persediaan barang`. Rejected because keeping it avoids route/behavior migration and lets the new capability be hardened independently.

### 2. Project current stock from ProductStock, not transaction replay

Build the stock matrix from `product_stocks` joined to active `locations` and their settings. For each product/location retain `quantity_tax`, `quantity_non_tax`, `broken_quantity_tax`, and `broken_quantity_non_tax`; derive primary Good as the first two and informational Broken as the latter two. Aggregate location → business → global in one grouped read. Do not use `products.product_quantity` or replay transactions for this current-state page.

The global scope is every business and active location, with no business selector. Dedicated permission grants access to this deliberately global projection. This keeps the single product-wide minimum semantically aligned with the displayed stock.

Alternative considered: reuse the Stok Lintas Bisnis selected-business scope. Rejected because deselecting businesses would compare a partial stock total with a global threshold.

### 3. Reuse grouped-column behavior with an always-visible global column

Use a two-tier, horizontally scrollable header and sticky product identity patterned after Stok Lintas Bisnis. Initially render only the sortable global Good column. Global expansion adds one non-sortable business subtotal column per business without removing global. Expanding a business replaces its subtotal column with one column per active location under the business header. Store one global-expanded flag and a `setting_id => boolean` business expansion map in component state.

Sort controls and expansion controls are separate hit targets. Only global stock is sortable; child columns explain, rather than redefine, the global value.

Alternative considered: retain every business subtotal beside expanded locations. Rejected because the confirmed interaction replaces a business subtotal with its separated location columns, matching the existing report.

### 4. Centralize independent attention flags

Project status flags rather than a mutually exclusive classification. Minimum-stock rules use only global Good and the integer `product_stock_alert` already stored on Product. Preserve the existing integer field for this change; decimal stock can still be compared to an integer threshold without a schema migration. A later change may generalize minimum precision if operational units require fractional thresholds.

Use a fixed 90-day `Lama Tidak Terjual` rule for MVP, guarded by positive global Good stock and product age of at least 90 days. Do not derive it from the user-selected sales window, which only controls visible sales/financial measures.

Default ordering assigns deterministic priority ranks to attention flags, then product name and ID. Explicit user sorts override that priority.

### 5. Treat current persisted SaleDetail values as authoritative

Build product sales aggregates from fulfilled-report-eligible Sales using the established effective reporting-date expression, with start date inclusive and end fixed to today. Aggregate current SaleDetail quantity and tax-exclusive commercial value. Do not join or subtract SaleReturn/SaleReturnDetail aggregates; return effects already represented in the live SaleDetail must not be deducted twice.

This intentionally means a later return can change the result shown for the original sale date. The report describes current documents over a date scope, not an immutable historical ledger.

Alternative considered: attribute returns to return dates. Rejected because it conflicts with current settlement-mutated commercial rows and would require a broader immutable event model.

### 6. Calculate product financials from snapshots with explicit discount allocation

Calculate line revenue tax-exclusive, then proportionally allocate each eligible Sale header discount over its current eligible line DPP. Use decimal-safe rounding and assign the rounding remainder deterministically so allocations equal the header discount. Exclude shipping and operational expenses.

Calculate product HPP from captured cost-unit snapshots multiplied by current quantities. Attribute captured bundle-component HPP to the commercial parent product line/group so bundle cost is included once. Do not apply an explicit return-cost reversal in this report. Gross profit is allocated tax-exclusive revenue minus attributed snapshot HPP.

Track completeness alongside each aggregate. A contributing stock-managed line with a null/unusable snapshot marks cost and profit incomplete; the UI shows the partial number with a warning and sorting remains deterministic. Focused tests must reconcile the no-missing-snapshot fixture to sale revenue less allocated header discount and captured HPP.

Alternative considered: directly reuse the complete return-aware `SaleHppAggregateService`. Rejected for this per-product view because its explicit reversal paths could conflict with the confirmed current-SaleDetail authority; shared lower-level snapshot/bundle logic may still be extracted or reused.

### 7. Synchronize a fixed-end rolling period with one start-date picker

Represent period state as `startDate` plus a derived preset key. Normalize against `today()` in the application timezone. Standard presets calculate starts at `today - (N - 1)` for 7, 30, and 90 inclusive days. Manual start selection derives `diffInDays + 1`; when not standard, render one current system-generated `N Hari Terakhir` option that cannot be chosen as a reusable preset. Validate `startDate <= today` both client-side and server-side.

Changing this state resets pagination but does not affect stock/status aggregates or expansion state. No filter persistence or saved presets are introduced.

### 8. Keep inline mutation narrow, validated, and auditable

Place an accessible small action button beside each product name. Load modal values from a fresh authorized projection rather than trusting row payload. Validate product eligibility and a positive integer minimum, lock/reload the product for update, write only `product_stock_alert`, and record old/new values with actor and timestamp using the project's existing audit convention if available; otherwise add the smallest feature-owned audit record needed.

After success, close the modal and rerender cards/rows using current component filters and expansion state. If the row leaves the current filter, show explicit feedback and normalize pagination only when the page becomes invalid.

Alternative considered: redirect to Product Edit. Rejected because it interrupts operational review and exposes unrelated fields.

### 9. Query in bounded aggregate stages

Apply product identity/category/brand/status-capable predicates before pagination where possible. Fetch the page of product IDs, then load grouped stock hierarchy, sales/financial aggregates, and last-sale values in a bounded number of queries. Status filters that depend on aggregates should be implemented with aggregate subqueries/CTEs or a two-stage ID query before final pagination, not by loading the full catalog into PHP.

Use stable secondary ordering by product name and ID. Summary counts must call the same status projection logic rather than duplicate formulas.

### 10. Verify narrowly and reserve browser acceptance for humans

Automated work uses focused tests for permission denial/card visibility, stock bucket aggregation and reconciliation, statuses, period boundaries, current SaleDetail/return non-duplication, discount/HPP/profit math, missing snapshots, filter/sort behavior, and modal validation/update. Run only relevant test files or `php artisan test --filter=...`; do not plan or execute the full suite.

Supply a concise manual browser checklist for a human covering the reports card, Indonesian copy, horizontal matrix expansion, sorting, filters, generated period option, modal behavior, row disappearance, and permission denial. Implementation agents must not install or invoke Chrome/Chromium, Playwright, Selenium, or any browser command.

## Risks / Trade-offs

- [Existing sale data may have missing or zero cost snapshots] → Track completeness and visibly qualify cost/profit rather than silently treating unknown stock-managed cost as reliable zero.
- [Current SaleDetail mutation rewrites historical-period results] → State the current-document semantics and avoid implying an immutable historical ledger.
- [Bundle cost attribution can double-count parent and component snapshots] → Use one explicit parent/group attribution path with focused normal/bundle fixtures.
- [Header-discount allocation can drift by rounding] → Use decimal arithmetic, deterministic remainder assignment, and reconciliation tests.
- [A wide expanded table may be difficult on smaller screens] → Keep product and global stock sticky, use horizontal scrolling, and default all hierarchy groups collapsed.
- [Global access exposes cross-business financial data] → Require the dedicated permission at every boundary and do not inherit access from ordinary single-business report permissions.
- [Status filtering over aggregates can become slow] → Push aggregate filtering to SQL, paginate IDs first where valid, index/join existing product/location/sale keys, and assert bounded query behavior in focused tests.
- [One integer global minimum may be coarse for fractional-unit products] → Preserve current product semantics for MVP and defer fractional/per-location minimum configuration.

## Migration Plan

1. Register `stockInsights.access`, make it assignable through existing role/permission administration, and add it to Reports landing visibility gates without auto-granting broad roles unless the project's permission convention explicitly requires it.
2. Add the new card, route, projections, page, and focused modal update path. Apply only additive schema if an audit record cannot reuse existing infrastructure; do not rewrite stock, sales, returns, or historical costs.
3. Run focused automated tests only and provide the human browser checklist. Do not run browser automation or the full suite.
4. Deploy with the report hidden from users lacking the permission; grant permission deliberately after reviewing global financial visibility.

Rollback removes the card/route/application behavior and permission assignment while retaining any additive audit history. Existing reports and transaction data require no rollback.
