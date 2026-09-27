## Context

The Reports landing page already contains a "Pengiriman penjualan" card, but it is configured as a placeholder. The sample report under `report-sample/pengiriman-penjualan` shows a Mekari-style sales delivery report grouped by customer, with product/SKU, product name, unit, delivered quantity, amount, customer subtotals, grand total, filters, and exports.

Sales dispatch data already exists in `dispatches` and `dispatch_details`. The current Sales dispatch flow does not persist `dispatch_details.sale_detail_id`; it aggregates delivery rows by the dispatch composite key:

```text
sale_id + product_id + tax_id + bundle_id
```

That behavior is used by normal dispatch creation, import-created dispatches, and bundle-aware dispatch logic. Existing OpenSpec requirements for Sales dispatch also define bundle tax context as parent-first with standalone fallback.

## Goals / Non-Goals

**Goals:**
- Add a real "Pengiriman penjualan" report under Reports > Penjualan.
- Match the sample report structure: customer groups, product rows, subtotals, grand total, filters, and exports.
- Use approved dispatch rows as the source of delivered quantities.
- Use existing sale and bundle commercial data to calculate amounts without adding schema.
- Preserve current dispatch consistency by using the same composite key as dispatch creation and validation.
- Follow existing Reports module patterns for routes, controllers, Livewire components, filter data, query service, snapshot validation, and exports.

**Non-Goals:**
- No migration for `dispatch_details.sale_detail_id`.
- No backfill of historical dispatch data.
- No change to Sales dispatch creation, approval, stock, or serial-number behavior.
- No parent bundle revenue allocation unless a separate business rule is defined later.
- No PDF export unless requested separately; the current report stack should start with Excel and CSV like comparable reports.

## Decisions

### Use existing dispatch rows as the delivery source

The report will query `dispatches` joined to `dispatch_details`, scoped to `dispatches.status = APPROVED` and filtered by `dispatches.dispatch_date`.

Alternative considered: query `sale_details` and infer delivery from invoice lines. That would not represent actual delivery timing or partial dispatches, and it would fail the report's delivery-date semantics.

### Do not add `dispatch_details.sale_detail_id`

The existing dispatch table does not include `sale_detail_id`, and current dispatch producers do not write it. Normal dispatch rows can represent an aggregate of multiple sale detail rows with the same `sale_id + product_id + tax_id + bundle_id`, so a single detail foreign key would be ambiguous.

Alternative considered: add a nullable column and populate it for new rows. That creates mixed semantics: old/import rows remain null and aggregate rows may still not map to one sale detail. The report should instead respect the current read model.

### Join delivered quantity to commercial data by composite key

The query service will build two aggregates:

1. Delivery aggregate from approved `dispatches` and `dispatch_details`, grouped by customer and the dispatch composite key.
2. Commercial aggregate from `sale_details` and `sale_bundle_items`, grouped by the same composite key.

The report amount is calculated as:

```text
unit_amount = commercial_line_amount / ordered_quantity
jumlah = delivered_quantity * unit_amount
```

When the commercial aggregate is missing or has zero ordered quantity, the report still shows delivered quantity and uses zero amount to avoid fabricated totals.

### Keep bundle amount behavior conservative

Bundle component delivery rows will use the persisted bundle component `sub_total` when it exists. If a standard bundled component has zero commercial value because the parent bundle line carries the revenue, the delivery report will not allocate the parent amount across components.

Alternative considered: allocate parent bundle revenue to component rows. That would require a new business rule and could produce totals that do not match persisted sales rows.

### Reuse the SaleByCustomer report shape

Implementation should mirror the existing `SaleByCustomerReport` stack for filter drawer behavior, searchable customer/tag/category selectors, snapshot-gated exports, pagination, grouping, and tests. The query service differs because its base rows are delivery aggregates rather than sale detail rows.

## Risks / Trade-offs

- Bundle component rows with zero persisted commercial value will show zero amount -> document this as current persisted-data behavior and avoid inventing allocation.
- Dispatch rows without a matching sale commercial aggregate will show quantity with zero amount -> cover this with tests and keep the query left-joined so delivery visibility is not lost.
- Multiple sale details can collapse into one dispatch composite key -> group commercial rows by the same composite key before joining to avoid double counting.
- Same product can appear with different tax or bundle context -> always group and join with normalized `tax_id` and `bundle_id`, not `product_id` alone.
- Large date ranges may scan many dispatch rows -> use SQL aggregation in the query service and avoid per-row Eloquent loops for totals/export.

## Migration Plan

No database migration is required.

Implementation can be deployed as application code only:
- Add route/controller/view/Livewire/service/export/test files.
- Change the Reports landing card from placeholder to route-backed.
- Rollback by removing the new route/component/service/export files and restoring the card to placeholder.

## Open Questions

- Should a later change add PDF export to match the sample export dropdown exactly?
- Should bundle parent revenue ever be allocated to component delivery rows, and if so by quantity, cost, configured component price, or another rule?
