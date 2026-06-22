## Context

`Pengiriman pembelian` is already listed in the Reports landing page under `Pembelian`, but it is marked as a placeholder. The sample files in `report-sample/pengiriman-pembelian` show a Mekari/Jurnal purchase delivery report grouped by supplier with columns for product code context, product name, unit, received quantity, and amount. The local ERP equivalent of purchase delivery is the purchase receiving flow: `received_notes` and `received_note_details`, linked back to `purchases` and `purchase_details`.

Existing reports already provide the implementation pattern this feature should follow:

- `SaleDeliveryReport` for delivery-style grouping, applied-filter snapshots, and export guarding.
- `PurchaseBySupplierReport` for supplier filters, purchase tags, product categories, and purchase-side grouping conventions.
- `PurchaseReportQueryService` for approved receiving joins and cross-database compatible aggregate patterns.
- `ReportsController` landing-page configuration for permission-aware report cards.

## Goals / Non-Goals

**Goals:**

- Provide an actionable `Pengiriman pembelian` report for users with `purchaseReports.access`.
- Report approved purchase receiving activity using `received_notes.date` as the delivery date basis.
- Match the sample report's business grain: supplier-grouped product rows with unit, received quantity, amount, supplier subtotal, and grand total.
- Support date range, period preset, supplier, tag, product category, tag/category match logic, and sorting controls.
- Provide Excel and CSV exports that match the last applied filters and are not limited to the current page.
- Keep implementation read-only against purchase and stock data.

**Non-Goals:**

- Do not create or modify purchase receiving records.
- Do not add new stock, serial, payment, or purchase lifecycle behavior.
- Do not add a new database table or rewrite historical purchase data.
- Do not make PDF export part of the initial implementation, even though the source UI sample exposes a PDF option.
- Do not attempt exact visual parity with Mekari's Pixel UI; use the ERP's existing Bootstrap/Livewire report conventions.

## Decisions

### Use approved receiving notes as the source of truth

The report will query `received_notes` joined to `received_note_details`, `purchases`, `purchase_details`, and supplier/product metadata. Only `received_notes.status = APPROVED` rows count.

Rationale: this matches the local purchase receiving lifecycle and mirrors the sales delivery report's approved-dispatch rule. Pending or rejected receiving notes are not final business deliveries.

Alternative considered: using purchase invoice date and purchase detail quantity. That would reproduce purchase order/invoice lines, not actual received delivery activity, and would overlap the existing purchase list and purchase-by-supplier reports.

### Use receiving date as the report date basis

The date range will filter `received_notes.date`, not `purchases.date`.

Rationale: the report is about purchase delivery/receiving, so inclusion should be controlled by when goods were received. This also avoids hiding a receiving note simply because the purchase invoice date is outside the selected range.

Alternative considered: making date basis configurable. That adds UI and test complexity and is better suited to the broader purchase list report, not this delivery-specific report.

### Calculate amount by prorating purchase detail amount by received quantity

For each receiving detail, amount should be calculated from the associated purchase detail using:

```text
received_quantity * commercial_line_amount / purchased_quantity
```

The commercial line amount should use persisted purchase detail values and avoid recomputing tax or discounts from current settings. The implementation should normalize output rounding to two currency decimals to avoid floating artifacts like the sample XLSX `265000.000001`.

Rationale: a purchase detail may be partially received across multiple receiving notes. Counting the full line subtotal for every receiving detail would overstate amount.

Alternative considered: using `purchase_details.sub_total` directly. That only works when every purchase line is received exactly once and fully, which the receiving model does not guarantee.

### Resolve product and unit through purchase detail

The query should resolve product identity through `received_note_details.po_detail_id -> purchase_details.product_id`, then join/load `products`, `units`, and `base_units`.

Rationale: `ReceivedNoteDetail` stores `po_detail_id` and `quantity_received`; its direct `product()` relation points to `product_id`, which is not part of the receiving detail fillable/source shape. The purchase detail is the stable link to product name/code/unit/category and persisted line values.

Alternative considered: relying on `ReceivedNoteDetail::product()`. That risks empty product data unless a direct `product_id` column is present and populated in all environments.

### Reuse the report snapshot export guard

The Livewire component should create a snapshot after successful filter application and block Excel/CSV export when filters have changed or were never applied.

Rationale: this is already established in `SaleDeliveryReport` and `PurchaseReport`; it prevents exporting stale or unintended datasets.

Alternative considered: exporting current public component state directly. That is simpler but inconsistent with existing report safeguards and harder to reason about after pending filter edits.

### Implement as a normal Reports module page

Add a route/controller/view pair under `Modules/Reports`, a Livewire component under `app/Livewire/Reports`, supporting services under `app/Services/Reports`, and an export class under `app/Exports`.

Rationale: this matches current report ownership and keeps purchase report UI/service code with other reports rather than inside `Modules/Purchase`.

Alternative considered: implementing inside `Modules/Purchase`. That would place a report outside the Reports module conventions and duplicate navigation/permission patterns.

## Risks / Trade-offs

- Amount semantics may differ for tax-inclusive purchases, document-level discounts, or landed costs -> Use persisted purchase detail data and cover partial receiving, tax, and discount cases in tests; avoid recomputing from current tax settings.
- Category `Mencakup semua` is awkward for row-level product category filters because a single product normally has one category -> Preserve existing report convention, but tests should document the actual semantics for row inclusion.
- Supplier-group pagination can split a supplier across pages -> Follow existing row pagination patterns and mark continued groups or calculate subtotals in a way that remains correct across pages.
- XLSX sample includes title/date/currency metadata rows while current app exports often use direct headings -> Start with data/export parity for rows and totals; only add report metadata rows if implementation can do so without breaking existing export style.
- Query performance may degrade on large receiving datasets -> Aggregate in SQL, scope by `setting_id`, filter by receiving date early, and avoid N+1 loading for supplier/product/unit/category/tag data.
- The sample UI exposed PDF but the proposal excludes it -> Keep PDF out of tasks and tests so implementation does not accidentally broaden scope.
