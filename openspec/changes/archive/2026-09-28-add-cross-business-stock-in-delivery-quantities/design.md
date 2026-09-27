# Design

## Context

See `proposal.md` for motivation. The cross-business stock inventory report currently paginates active products, bulk-aggregates stock by product, business, and active location, then builds global/business/location row data. Its Excel path retrieves the entire filtered product set before assembling the matrix. Product unit metadata is not currently part of the report row model.

`ProductQuantityProjectionService::getOnOrderStock()` already expresses the intended purchase semantics, but it operates on one product and one setting and queries approved receipts once for every matching purchase detail. Calling it for a cross-business matrix would create multiplicative query growth. It also casts results to integer even though purchase and receiving quantities now support decimal base-unit quantities.

The Product list's denomination convention uses the largest configured conversion plus a base-unit remainder. Its implementation uses integer-oriented remainder operations, so fractional report quantities need a decimal-safe implementation while retaining the same visible convention.

## Goals / Non-Goals

**Goals:**

- Add exact, business-owned outstanding purchase quantities without attributing an unreceived purchase to a location.
- Keep canonical aggregates numeric and full precision until the presentation boundary.
- Apply one display mode consistently across screen and export.
- Bound query count for paginated display and bound memory for export.
- Keep MySQL production behavior and SQLite focused tests compatible.

**Non-Goals:**

- Predicting the eventual receiving location, arrival date, supplier shipment status, or purchase lead time.
- Treating outstanding purchases as on-hand, sellable, reserved, tax-classified, good, or broken stock.
- Changing purchase or receiving lifecycle rules, stored quantities, product conversions, or availability-filter semantics.
- Adding an in-delivery serial-number dialog or a new permission.
- Running the full automated test suite.

## Decisions

### 1. Aggregate outstanding quantities by purchase detail before product and business

Build an approved-receipt aggregate keyed by `received_note_details.po_detail_id`, restricted to receiving notes whose status is exactly approved. Join it to eligible purchase details and purchases, calculate `GREATEST(detail quantity - COALESCE(approved received, 0), 0)` per detail, then sum by product and `purchases.setting_id` for the requested product and business IDs.

Keep quantities decimal through SQL and PHP. The service returns a sparse matrix keyed by product ID and setting ID; missing keys mean zero. Derive each global value by summing the selected business entries already loaded into that row.

Rationale: clamping before the final sum prevents an over-received detail from cancelling another detail's outstanding quantity. Business ownership comes from the purchase header; no receiving location is authoritative before receipt. This also replaces the reference service's per-detail query pattern with a bounded bulk query.

Alternative considered: call `ProductQuantityProjectionService` per product and business. Rejected because its nested queries produce N+1 behavior and integer coercion.

Alternative considered: subtract total receipts from total orders per product/business. Rejected because it clamps only after aggregation and can incorrectly offset separate purchase details.

### 2. Extend the row model with numeric aggregates and unit metadata

Eager-load each page or export batch's base unit and conversions with only the identifiers, short names, and conversion factors needed for display. Resolve the largest valid positive conversion once per product while building its row. Add numeric `total_in_delivery` and business-level `in_delivery` values to the existing row model, plus compact denomination metadata.

Do not add in-delivery entries to location data. When a business is expanded, its header/body layout retains one business-owned in-delivery column beside the repeated location Good/Bad pairs.

Rationale: one canonical numeric row supports both display modes, prevents formatted strings from contaminating totals, and avoids repeating conversion resolution per cell.

### 3. Use one decimal-safe quantity presenter

Introduce or adapt a reusable quantity presenter that accepts a canonical numeric quantity, base-unit metadata, largest-conversion metadata, and display mode.

Decimal mode rounds only for output to two places, uses a comma decimal separator, emits two fractional digits when the rounded value is fractional, and emits no separator or fractional digits when it is whole. Conversion mode calculates:

```text
whole converted units = floor(quantity / factor)
base remainder = quantity - (whole converted units * factor)
```

It formats both components under the same decimal rule and does not use PHP's integer `%` operator. Following the Product list convention, it uses only the largest conversion and the base-unit remainder. With no conversion it appends the base unit to the canonical quantity; with no base unit it returns the formatted bare quantity.

Rationale: subtraction preserves fractional remainders while matching the familiar Product list output. Formatting is separate from aggregation, so switching modes cannot change business calculations.

Alternative considered: copy `ProductDataTable::formatQuantityValue()` unchanged. Rejected because its modulo behavior is not safe for decimal quantities and it does not implement the required comma/two-place rule.

### 4. Treat display mode as report and export state, not a data filter

Add a validated `decimal`/`conversion` Livewire property with decimal as the default. It controls only presentation and is passed into export construction. It does not reset pagination, alter product matching, change availability filtering, or participate in stock/in-delivery aggregation inputs.

On screen, format canonical row values at the presentation boundary using already-loaded unit metadata. A mode-only Livewire update may rerender the component, but the component/service must cache or otherwise reuse the current page's aggregate row payload so the mode change itself does not issue stock or purchase aggregation queries solely for formatting.

For Excel, decimal mode writes numeric quantities and applies a custom number format that uses up to two decimals while suppressing zero decimal places for whole values. Conversion mode writes unit-denominated text. Export headers add one global in-delivery column and one business-level in-delivery column while retaining fully expanded Good/Bad location detail.

Alternative considered: format quantities in SQL. Rejected because it couples locale/presentation to arithmetic, prevents numeric Excel cells, and duplicates conversion logic.

### 5. Keep screen queries page-scoped and make export work bounded

The screen flow obtains product IDs from the existing database paginator, then performs bulk stock aggregation, bulk in-delivery aggregation, and eager unit loading for only that page. Query count must be invariant with respect to the number of product/business/location cells.

Refactor export traversal to process the filtered ordered product query in stable chunks. For each chunk, bulk-load stock, in-delivery, and unit metadata, build export rows, and release the chunk before continuing. The export writer may spool rows through a generator or another supported bounded mechanism rather than retaining all report models and relationship rows.

Before adding schema changes, inspect existing composite/single-column indexes and query plans for purchases, purchase details, receiving notes, and receiving-note details. Add an index migration only if the actual access path lacks suitable coverage; no schema change is assumed by default.

Rationale: pagination alone protects the screen but not an all-results export. Stable chunking keeps peak application memory tied to batch size.

Alternative considered: preserve `get()->all()` export loading. Rejected because the requested performance boundary includes large exports.

### 6. Use focused verification only

Add focused service, Livewire/feature, formatter, and export tests. Cover purchase-status and approved-receipt semantics, detail-level clamping, business/global scope, decimal quantities, both display modes across all quantity scopes, expansion behavior, numeric/text export behavior, bounded screen query growth, mode-switch query reuse, and chunked export completeness.

Run only the relevant test files or focused filters. Do not plan or run the full suite.

## Risks / Trade-offs

- **[Risk] A mode-only Livewire rerender normally recomputes report data.** → Cache the canonical current-page payload using keys derived from authorization scope, filters, page, and source revision/short lifetime; invalidate it when data-affecting state changes, while keeping display mode outside the aggregation key.
- **[Risk] SQL expressions differ between MySQL and SQLite.** → Use query-builder-compatible derived aggregates and a portable non-negative expression, covered by focused SQLite tests and an inspected MySQL query plan where available.
- **[Risk] Binary floating-point can produce values such as `5.999999`.** → Preserve decimal database strings or decimal-safe arithmetic through aggregation/presentation and normalize only at the two-decimal output boundary.
- **[Risk] Conversion factors can be missing, zero, fractional, or malformed legacy data.** → Use only valid positive factors; otherwise fall back to base-unit display, and cover fractional-factor behavior in formatter tests.
- **[Risk] Text conversion quantities in Excel cannot be summed.** → Preserve numeric cells in decimal mode and make the trade-off explicit when the user selects conversion mode.
- **[Risk] New indexes can slow purchase/receiving writes.** → Require evidence from existing-index inspection and query plans before introducing any migration.

## Migration Plan

Deploy the query, row-model, UI, formatter, and export changes together. No data backfill is required because the projection is calculated from existing purchases and approved receiving notes. If query-plan inspection proves an additional index necessary, deploy its additive migration before enabling the report code. Rollback restores the previous report code and removes only an additive index introduced by this change; no business data needs reversal.
