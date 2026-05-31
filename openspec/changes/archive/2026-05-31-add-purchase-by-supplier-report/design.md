## Context

The ERP already has a modern Reports module and a `Daftar Pembelian` report at `/reports/purchase-report`, implemented with `App\Livewire\Reports\PurchaseReport` and shared report query/filter/export services. That report is line-detail oriented and uses the existing `purchaseReports.access` permission.

`Pembelian Per Supplier` is a different report contract. The supplied sample groups purchase detail rows by supplier, displays a smaller column set, and shows a running supplier total. The first version should be view-only: no Excel, CSV, or PDF export behavior.

## Goals / Non-Goals

**Goals:**

- Add `Pembelian Per Supplier` under `Laporan -> Pembelian`.
- Reuse `purchaseReports.access`.
- Render `Faktur pembelian` purchase detail rows grouped by supplier.
- Hide suppliers that have no matching purchase detail rows.
- Default date filters to the current calendar month.
- Support sample-aligned filters for supplier, tag, product category, tag/category matching logic, period preset, and sort order.
- Use `purchase_details.sub_total` for `Nominal tagihan`.
- Compute `Total nominal tagihan` as a running total within each supplier group.
- Keep normal row pagination for the first version.

**Non-Goals:**

- No export implementation or export UI behavior beyond any disabled/non-functional placeholder that may be needed for visual consistency.
- No support for purchase orders or purchase quotations; the report is fixed to `Faktur pembelian`.
- No global report variant.
- No new permission.
- No schema changes.
- No changes to the existing `Daftar Pembelian` report contract.

## Decisions

### Decision 1: Create a sibling report instead of extending `Daftar Pembelian`

Implement a new route, controller entry, Livewire component, query service, filter DTO, and validator for `Pembelian Per Supplier`.

Rationale:
- `Daftar Pembelian` has a wide 40-column contract and export parity requirements.
- `Pembelian Per Supplier` has supplier grouping, subtotals, and a much smaller sample-specific column set.
- Separate services keep the two report contracts testable without conditional branching throughout the existing report.

Alternatives considered:
- Add a template switch to `PurchaseReport`: lower route/menu churn, but mixes two report grains and export contracts.
- Reuse `PurchaseReportQueryService`: tempting because both use `purchase_details`, but the grouping/running-total semantics are distinct enough to justify a separate mapper.

### Decision 2: Root report rows in `PurchaseDetail`

Build results from `purchase_details` joined or eager-loaded with `purchases`, `suppliers`, `products`, product units, product categories, and purchase tags.

Rationale:
- The sample rows represent product lines, not purchase headers.
- `purchase_details.sub_total` is the chosen source for `Nominal tagihan`.
- Header fields such as date, reference, supplier, and note can be repeated from the related purchase.

### Decision 3: Group after applying filters and sorting

Filter the detail-row query first, sort by supplier and transaction date descending, then render rows grouped by supplier. Use normal row pagination, accepting that a supplier group can span pages.

Rationale:
- The user selected normal row pagination.
- This matches the existing Livewire report pagination model.
- Paginating by supplier group would produce uneven pages and more complex count logic.

Implementation note:
- The query should order by supplier name as a stable grouping key, then by purchase date descending inside each supplier unless `Urutkan berdasarkan` chooses total purchase ordering.
- If sorting by `Total pembelian`, compute supplier totals in a subquery and order supplier groups by that aggregate while keeping date-desc row order within each supplier.

### Decision 4: Keep transaction type fixed to `Faktur pembelian`

Do not expose working purchase order or purchase quotation modes in v1.

Rationale:
- The user selected `Faktur pembelian` only.
- The current purchase report infrastructure is based on `purchases` and `purchase_details`.
- Showing unsupported transaction types would imply behavior the system does not provide.

### Decision 5: Implement searchable multi-select filters without preloading large datasets

Supplier, tag, and product category filters should search server-side after a minimum input length and preserve selected labels as removable pills.

Rationale:
- Supplier, tag, and category datasets can grow.
- The existing purchase list report already uses this style for supplier and tag filters.

Filter semantics:
- Supplier: OR across selected suppliers.
- Tag: `Salah satu` means a purchase has any selected tag; `Mencakup semua` means a purchase has every selected tag.
- Category: `Salah satu` means a product category matches any selected category; `Mencakup semua` means it matches every selected category. Because products currently have one `category_id`, selecting multiple unrelated categories with `Mencakup semua` will normally return no rows.

### Decision 6: Use purchase note for `Keterangan`

Map the `Keterangan` column to the related purchase note/memo, not product description.

Rationale:
- The user selected purchase note/memo.
- The sample often shows blank descriptions; this ERP does not appear to have a dedicated purchase-detail description field.

### Decision 7: Defer export entirely

Do not create export classes or active export actions for this change.

Rationale:
- The user explicitly scoped this to the view.
- Export grouping raises separate questions about CSV versus XLSX shape and can be specified later.

## Risks / Trade-offs

- Category `Mencakup semua` can often return no rows for multiple selections -> Document and test literal behavior so it is not confused with OR semantics.
- Running totals with normal row pagination can restart context awkwardly when a supplier spans pages -> Keep v1 simple and verify displayed running totals are based on the rows shown in supplier/date order.
- Sorting by total purchase requires supplier aggregate logic -> Use a grouped subquery rather than per-row PHP aggregation over all results.
- Large filtered ranges can produce expensive grouped queries -> Keep explicit `Filter` application, pagination, indexed date/supplier joins, and focused query tests.
- Reusing `purchaseReports.access` broadens access to the new report for current purchase-report users -> This is intentional and matches the user's selected permission behavior.

## Migration Plan

No database migration is expected.

Deployment steps:
- Add route, controller method or route closure, Livewire component, services, view, and sidebar entry together.
- Ship focused tests with the change.
- Existing `/reports/purchase-report` behavior remains unchanged.

Rollback:
- Remove the new route/menu/component/service/view/test additions. No data rollback is required.

## Open Questions

None blocking.
