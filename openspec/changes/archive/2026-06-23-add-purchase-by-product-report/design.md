## Context

`Pembelian per produk` is already listed in the Reports landing page under the Pembelian tab, but the card is a placeholder. The sample files under `report-sample/pembelian-per-produk` define a Mekari/Jurnal-style product aggregate report with 219 product rows, XLSX metadata rows, CSV row export, filters, sorting, pagination, and export controls.

The codebase already has adjacent report implementations:

- `SaleByProductReport` provides a product aggregate pattern with purchase-like columns, return aggregation, tax-exclusive value calculation, snapshot-gated XLSX/CSV exports, and Livewire filter state.
- `PurchaseDeliveryReport` provides recent purchase report route/card/export patterns under `purchaseReports.access`.
- `PurchaseBySupplierReport` provides purchase-side source handling, supplier/tag/category filters, and tax-exclusive purchase value semantics.

The feature should remain read-only and use existing `purchases`, `purchase_details`, `purchase_returns`, `purchase_return_details`, product, unit, supplier, category, and tag tables.

## Goals / Non-Goals

**Goals:**

- Add an actionable `Pembelian per produk` report under `Laporan -> Pembelian`.
- Match the first-scope sample aggregate shape: one row per product/unit, purchase quantity, return quantity, purchase value, return value, average purchase value, and totals.
- Use active setting scoping and `purchaseReports.access`.
- Use existing report UI patterns for date filters, filter drawer, apply/cancel/reset behavior, pagination, empty state, and snapshot-gated XLSX/CSV exports.
- Keep calculations compatible with SQLite-focused tests and MySQL/MariaDB production.
- Preserve purchase, receiving, stock, serial, payment, purchase return, and report behaviors outside this read-only report.

**Non-Goals:**

- PDF export.
- Purchase order and purchase quotation transaction sources from the sample `Tipe transaksi` control.
- The sample `Lihat versi lebih detail` mode that adds transaction numbers and discount grouping.
- New permissions or database migrations.
- Recomputing historical purchase or purchase return values from current tax/product settings.

## Decisions

### 1. Implement as a Reports-module read-only report

Add a `PurchaseByProductReportController`, route, module view, Livewire component, query/filter/validator/snapshot services, export class, and focused tests following the existing Reports module conventions.

Rationale: this keeps the feature aligned with `SaleByProductReport`, `PurchaseDeliveryReport`, and the existing reports landing card. The report does not belong in `Modules/Purchase` because the user-facing entry point and report infrastructure live in `Modules/Reports`.

Alternative considered: add logic under the Purchase module. Rejected because it would split report navigation/export patterns across modules.

### 2. First scope uses purchase invoice details only for purchase quantity/value

The purchase side aggregates `purchase_details` joined to `purchases`, filtered by `purchases.date` and active setting.

Rationale: adjacent purchase reports treat `Faktur pembelian` as the implemented invoice source, and the sample transaction-type options for purchase orders/quotations are broader than current first-scope implementation patterns. This also avoids mixing committed purchase invoices with pre-invoice business documents.

Alternative considered: include purchase orders and quotations immediately. Rejected for first scope because the current schema/report patterns need separate source modeling and column reconciliation.

### 3. Return side uses lifecycle-valid purchase return details

The return side aggregates `purchase_return_details` joined to `purchase_returns`, filtered by `purchase_returns.date`, active setting, approved document status, and final or post-dispatch lifecycle states that represent goods/settlement progress. The implementation should use case-insensitive comparisons because historical migrations normalize status casing over time.

Baseline inclusion rule: include purchase returns whose `approval_status` is approved and whose unified status is effectively in or beyond return execution, such as `IN_RETURN`, `SETTLEMENT_CONFIRMATION_PENDING`, `WAITING_REPLACEMENT_GOODS`, `PARTIAL_SETTLEMENT`, or `COMPLETED`, using available persisted fields (`approval_status`, `return_dispatch_status`, settlement item presence/status) rather than instantiating every row.

Rationale: draft, pending, rejected, and merely approved-but-not-dispatched returns should not affect `Qty retur` and `Nilai retur`. A return should count when it has progressed beyond approval into actual return execution or settlement.

Alternative considered: count every approved purchase return. Rejected because approved-but-not-dispatched returns can overstate actual returns. Alternative considered: count only completed returns. Rejected because the sample metric is return activity, and in-progress returned goods can be operationally relevant.

### 4. Values are tax-exclusive line commercial values

For tax-included purchases, subtract `product_tax_amount` from the detail `sub_total` before aggregation. For tax-exclusive purchases, use `sub_total`. Apply the same principle to purchase return values when source purchase tax-inclusion context can be resolved through `purchase_return_details.po_id`; otherwise use the persisted return detail `sub_total` as the safest historical value.

Rationale: existing purchase-by-supplier and sale-by-product behavior use tax-exclusive commercial values for comparable report totals, and the sample labels values as `(dalam IDR)` purchase values rather than tax rows.

Alternative considered: use gross values including tax. Rejected because it would conflict with existing purchase report semantics and make tax-included rows inconsistent.

### 5. Aggregate row grain is product identity plus unit

Group by product id where available, product code/name fallback, and resolved unit display. Blank product codes remain valid. Unit display should prefer product unit relationships (`units.short_name`, base unit, product unit) with safe fallback.

Rationale: the sample has every SKU blank but still reports product rows. Existing imported/historical detail rows may have product snapshots even when product records are missing.

Alternative considered: group only by product id. Rejected because historical rows with null product id would collapse incorrectly.

### 6. Snapshot-gated exports mirror report rows, not just current page

Use the existing snapshot pattern so users must apply filters before export and re-apply after changing pending filters. XLSX should include metadata rows; CSV should contain only headings and data rows, matching the sample difference.

Rationale: this is consistent with recent report implementations and prevents stale export surprises.

## Risks / Trade-offs

- Purchase return lifecycle ambiguity -> Define explicit inclusion tests for rejected, approved-not-dispatched, in-return, partial settlement, and completed return states.
- Historical null product IDs or blank product codes -> Group by product id plus snapshot code/name/unit fallbacks and test blank-code rows.
- Tax-included return values without resolvable source purchase -> Use persisted return detail values and document this fallback in tests.
- Decimal drift between CSV and XLSX totals -> Use decimal-safe SQL expressions and assert rounded display/export values rather than raw binary float equality.
- Category `Mencakup semua` with one category per product -> Preserve existing report behavior: products must match selected categories, and all-match behaves consistently with existing category filter semantics.
- Large datasets -> Aggregate in SQL, paginate aggregated rows, and avoid eager loading row-by-row model graphs for the core report.

## Migration Plan

1. Add report route, controller, module view, Livewire component, services, export class, and tests.
2. Update Reports landing configuration so `Pembelian per produk` links to the new route and no longer uses placeholder treatment.
3. Run focused feature/Livewire tests for purchase-by-product and landing navigation.
4. Rollback is code-only: remove the route/card link and report classes/views to return the card to placeholder behavior. No schema rollback is required.

## Open Questions

- Whether a later change should implement the sample PDF export.
- Whether a later change should implement purchase order/quotation sources under `Tipe transaksi`.
- Whether a later change should implement the detailed transaction-number/discount mode.
