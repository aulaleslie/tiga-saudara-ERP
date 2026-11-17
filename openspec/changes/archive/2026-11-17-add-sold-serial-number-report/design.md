# Design

## Context

See proposal.md for motivation and specs/sold-serial-number-report/spec.md for the behavior contract.

The existing `SaleByProductReport` component provides searchable product selection, applied filters, and pagination; `HasReportSettingScope` sanitizes authorized businesses. Reports routes use `saleReports.access` and exports use Maatwebsite Excel. The product catalog is global; transaction scope belongs to sales, not `products.setting_id`.

Approved regular-sales and POS dispatch details store historical serial strings, including bundle components. Current serial status and `dispatch_detail_id` can change after a return/resale. `sales_order_serial_tracking` is unique by sale and serial and cannot alone distinguish repeated dispatch occurrences on the same sale. `SaleSerialDisplayResolver` is a reference for legacy returned metadata, not a complete report projection: its badge decisions are view-specific.

Standard return receiving records custody and SALE_RETURNED history through `StandardReturnCustodyService`. Standard replacement dispatch can be recorded on `sale_return_item_settlements` (`dispatch_kind`, `dispatch_serial_number_id`, `dispatched_serial_number`, final DISPATCHED status), without a normal sale dispatch detail. POS replacement dispatches have `pos_return_line_id`, `replacement_of_dispatch_detail_id`, and `replacement_returned_serial_id`. Cross-owner POS replacements can create a separate replacement-owner sale, preserving original sale date; they must not be counted again as a fresh customer sale.

Existing aggregate sales reports have fulfilled-sale eligibility and effective financial reporting dates. This new operational serial report intentionally uses approved physical serial occurrences, including partially dispatched sales, and persisted original `sales.date`. It does not change those other reports or their specifications.

## Goals / Non-Goals

**Goals:** A read-only occurrence projection shared by preview and export, with explicit lineage and bounded database access. Reuse report integration and authorization patterns while keeping financial aggregation logic separate.

**Non-Goals:** New transaction ledgers, schema changes, lifecycle repair, financial reconciliation, historical as-of snapshots, PDF/CSV, additional output columns, and changes to stock or return execution. Original-goods repair/cannot-process dispatch is not a different-unit replacement; this proposal does not add a repair-status report or restore received originals as replacement SNs.

## Decisions

### 1. Add a dedicated report pipeline

Proposed classes are `SoldSerialNumberReport`, filter/validator/query or projection services under `app/Services/Reports`, and `SoldSerialNumberReportExport`. Add the thin report controller, page/view, route, and landing/navigation entry following `Modules/Reports` patterns. Authorize both page and Livewire generate/export actions through `saleReports.access`, retaining global Super Admin behavior.

Alternative: extend the aggregate product report. Rejected because its row grain, date basis, fulfilled-sale rules, and financial return handling differ.

### 2. Identify original occurrences before resolving outcomes

Load approved dispatch candidates for selected original sale businesses and original date range. Internally identify an occurrence by sale, dispatch detail, product, normalized serial identity, and dispatch ordering. Keep these keys private; output contains five columns only. Normalize serials using existing conventions and preserve their string value in output. Deduplicate repeated serial entries within one occurrence and overlapping selections, never globally by serial text.

Use exact source dispatch/detail references for receipt and replacement evidence; associate events chronologically to the matching occurrence when legacy evidence lacks an exact reference. Current SOLD status or a non-null tracking return date is corroborating evidence, never the sole historical source. Do not drop historical serial arrays solely because active dispatched quantity was reduced by a return. Units without SN contribute no rows.

Alternative: query only currently SOLD serial records. Rejected because it loses original dates and mixes return/resale occurrences.

### 3. Normalize receipt and replacement evidence into occurrence edges

Batch-load receipt evidence from custody/received return details and serial history, including legacy received-return metadata supported by existing flows. A requested or approved return without physical receipt does not close an occurrence. Normalize modern and legacy sources to one physical receipt per occurrence to avoid double subtraction.

Normalize standard dispatched settlement replacements and POS approved dispatch replacements into directed edges from the received occurrence to the replacement serial occurrence. A standard settlement approval awaiting dispatch is not outbound evidence; require its completed dispatch state. Prefer explicit returned-serial and source-detail links, supplemented by POS return-line linkage. Keep edges specific to product, sale occurrence, and returned serial rather than joining only on serial strings.

Walk edges to a terminal active serial with a visited set; a received terminal node without an approved successor emits no row. Track one logical root sale unit and emit at most one row. Do not constrain outcome-event dates to the report period: later receipts/replacements change older periods. New ordinary resale dispatches start new roots.

Recognize cross-owner replacement-generated sales from persisted POS return execution lineage, suppress their duplicate roots, and preserve the original customer's sale/date/business attribution. Resolve associated lineage internally without emitting another business's standalone transactions. Current original sale business remains the report scope anchor.

Alternative: use dispatch JSON alone or reuse display badges directly. Rejected because standard settlement dispatches and repeated replacement chains need independent evidence and occurrence handling.

### 4. Resolve product selection and presentation independently

Expand selected bundle IDs through dispatched bundle/component lineage, not only today's bundle composition. Direct component selection also matches component occurrences inside bundles. Union selections by occurrence identity. Use current component product names, falling back to existing sale snapshot naming conventions if the catalog entry is unavailable. Preserve bundle component identity through replacement chains.

Sort the final projected rows by original sale date, current product name, SN, then internal sale/detail/occurrence identifiers for stable pagination. Derive numeric day/month/year from the date-only original sale field; do not use reporting overrides, dispatch timestamps, or timezone conversion of a date-only value.

### 5. Share applied inputs and row mapping across preview and XLSX

Require product selection and inclusive valid dates before querying. Use the sales-report business selector default and authorized fallback, with no data access if the user has no authorized business. Keep product searches global with token matching and product-code labels. Store applied filters; block export before applying or while edited inputs differ. Reauthorize and sanitize at every generation/export boundary.

Use one ordered projection and mapping for paginated preview and complete XLSX export. Preserve SN cells explicitly as text, numeric date parts as numeric cells, and exactly five headers: Product name, SN, Tgl, Bulan, Tahun. No Type column or summary rows. An empty export has headers only. Preview/export parity assumes unchanged committed source data; each generation reflects current outcomes, not a frozen historical dataset.

Use batched relation loads and chunked candidate reading, avoiding queries per serial. Complete replacement resolution before pagination so hidden originals cannot leave inconsistent page counts. A shared projection may need materialized row ordering for final sorting; keep this scoped to validated product/date/business candidates rather than all serials. No database-specific JSON expansion is required, preserving focused SQLite test compatibility.

### 6. Verify the changed behavior with focused tests

Add focused query/projection tests for ordinary and POS dispatches, partial dispatch, return receipt cutoff, pending/rejected dispatch exclusion, partial returns, new-sale resales, same-sale repeated occurrences, standard settlement replacement, POS replacement, A-to-B-to-C chains, replacement receipt with no successor, and cross-owner duplicate-root suppression. Include dates outside the selected period and a reporting-date override to prove original-date semantics.

Add filter/Livewire tests for required products/dates, inclusive boundaries, global token search, bundle/component overlap, current names, authorized business sanitization, unauthorized access, and stable pagination. Add XLSX assertions for complete ordered preview parity, exact five columns, numeric date parts, leading-zero SNs, empty results, and stale-filter blocking.

Run `php artisan test` with explicit paths or filters for the new report tests and directly related scope/navigation checks. Do not run the full suite or add broad benchmarks by default. Broaden checks only for a demonstrated issue. Planning validation uses `openspec validate add-sold-serial-number-report --strict`.

## Risks / Trade-offs

- [Legacy lineage can be incomplete] → Prefer exact links and audited receipt/history evidence. Never guess a replacement by matching SN text alone; surface irreconcilable evidence through existing diagnostics and do not fabricate a successor. Cover legacy receipt fallback explicitly.
- [Same serial can have several sale/dispatch occurrences] → Match receipt chronology within exact sale/detail lineage; tests must distinguish earlier receipts from later resales.
- [Two replacement storage paths] → Normalize standard settlements and POS dispatches before traversal; do not require a normal dispatch row for standard replacement.
- [Replacement-generated sale counted twice] → Suppress proven replacement roots and test cross-owner chains.
- [Older results change] → State the latest-outcome/original-sale-date basis near filters; do not label the report as an as-of snapshot.
- [Large selections increase projection memory] → Scope first, batch evidence loads, chunk candidates, and avoid N+1 traversal. Performance redesign needs measured evidence rather than a new ledger in this change.

## Migration Plan

No migration or backfill is planned. Deploy additive report classes, views, route, and navigation after focused tests pass. Existing sales-report users receive access through the existing permission. Rollback removes the report integration and its additive files; no transaction data rollback is needed.
