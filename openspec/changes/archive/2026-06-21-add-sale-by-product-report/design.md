## Context

The Reports landing page already contains a "Penjualan per produk" card in the Penjualan tab, but it is configured as a placeholder. The sample files under `report-sample/penjualan-per-produk` show a Mekari-style product aggregate report with date filters, a richer filter drawer, paginated rows, and XLSX/CSV exports.

The exported sample has this aggregate shape:

```text
Kode Produk
Nama Produk
Kuantitas Terjual
Kuantitas Retur
Satuan
Total Nilai terjual
Total Nilai Retur
Harga Penjualan Rata-rata
```

The current Reports module already has the right implementation pattern: route/controller wrapper under `Modules/Reports`, Livewire components under `app/Livewire/Reports`, report filter/validator/query/snapshot services under `app/Services/Reports`, export classes under `app/Exports`, and focused feature tests under `Modules/Reports/Tests/Feature`. `SaleByCustomerReport` and `SaleDeliveryReport` are the closest UI and service patterns.

Sales invoice data exists in `sales` and `sale_details`. Sales return data exists in `sale_returns` and `sale_return_details`. Existing Sales Return lifecycle code treats returns in `Awaiting Settlement` and `Completed` as received returns for source-sale return status synchronization, so those statuses are the safest reporting source for actual returned quantities.

## Goals / Non-Goals

**Goals:**
- Add a real "Penjualan per produk" report under Reports > Penjualan.
- Match the sample aggregate columns, total row, period metadata, and XLSX/CSV export shape.
- Use sales invoice rows as the first-scope transaction source.
- Use received sales return rows for return quantities and values.
- Reuse existing Reports module patterns for route/controller/view/Livewire/filter data/query service/snapshot/export/tests.
- Preserve snapshot-gated export behavior so exports match the last applied filters.
- Avoid schema changes and avoid changing Sales or Sales Return lifecycle behavior.

**Non-Goals:**
- No PDF export in this change.
- No "Lihat versi lebih detail" transaction-number/discount mode in this change.
- No quotation or sales-order reporting in this change.
- No new permission; the report is gated by `saleReports.access`.
- No database migration or historical data rewrite.
- No change to invoice, dispatch, payment, tax, POS, or Sales Return workflows.

## Decisions

### Use sales invoices as the first transaction source

The first implementation will calculate sold quantities and sold values from `sales` joined to `sale_details`, filtered by `sales.date` and scoped to the current `setting_id`.

Alternative considered: support the sample's full transaction-type filter (`Faktur penjualan`, `Pemesanan penjualan`, `Penawaran penjualan`) immediately. This was rejected for the first change because the codebase has a separate `Quotation` module but no comparable first-class sales-order document table. Mixing quotation rows into an invoice sales report would require separate document semantics, value scaling checks, permissions, and filter behavior.

### Count only received returns

The return aggregate will use `sale_returns` joined to `sale_return_details`, filtered by `sale_returns.date`, scoped to the current `setting_id`, and limited to statuses whose normalized value is `AWAITING SETTLEMENT` or `COMPLETED`.

Alternative considered: count all non-rejected/non-deleted returns. That would include returns not yet received and could overstate actual returned quantity. Existing `SaleReturnLifecycleSyncService` already treats `Awaiting Settlement` and `Completed` as received returns, so the report should follow that local domain rule.

### Use tax-exclusive line commercial values

`Total Nilai terjual` will be calculated from line commercial values before VAT. For tax-included sales, subtract `sale_details.product_tax_amount` from `sale_details.sub_total`; for tax-exclusive sales, use `sale_details.sub_total`. `Harga Penjualan Rata-rata` is calculated as:

```text
Total Nilai terjual / Kuantitas Terjual
```

with zero-safe handling when sold quantity is zero.

Alternative considered: use invoice line `sub_total` as-is. The sample values indicate tax-exclusive amounts: for example, one row value multiplied by 11% VAT reconciles to a round tax-inclusive selling amount. Existing sales-by-customer report code also subtracts product tax for tax-included sales, so this report should align with that behavior.

### Merge sold and return aggregates by product and unit

The query service will build two aggregate streams and merge them by product identity and unit:

```text
sales + sale_details
    -> sold_quantity, sold_value

sale_returns + sale_return_details
    -> return_quantity, return_value

merged by product_id/product_code/product_name/unit
```

When `product_id` is present, it is the primary identity. Product code/name from detail snapshots remain available so historical rows still display meaningful values if product records are later changed or missing.

Alternative considered: group only by product code or only by product id. Code-only grouping can merge unrelated historical products if codes change or are blank. ID-only grouping can lose meaningful grouping for deleted/null products. The report should prefer product id while retaining snapshot fallback fields.

### Keep filters close to comparable reports

The Livewire component will support date range, period preset, customer, tag, product category, product, tag/category match logic, sort field, and sort direction. The product filter should be searchable like customer/category/tag filters and should query products scoped to the active setting.

Sorting will support product name, product code, sold quantity, return quantity, sold value, and average sales value. Sorting should be stable with a deterministic product-name/product-id fallback.

### Reuse snapshot-gated XLSX/CSV export behavior

The report will create a snapshot when filters are applied and block export when filters have not been applied or have changed since the snapshot. XLSX exports will include metadata rows like the sample:

```text
<company name>
Penjualan dengan Produk
<start date> - <end date>
(dalam IDR)
```

CSV exports will contain the table headers and rows without extra formatting, matching existing report export conventions.

## Risks / Trade-offs

- Return values for legacy returns may not have full tax-included context -> calculate from persisted return detail values and cover expected behavior with tests; do not invent tax allocation rules that are not present in the source data.
- Product code is blank in the sample but should be supported locally -> display persisted product code when available and allow blank/`-` fallback without blocking rows.
- Same product sold under multiple units could merge incorrectly -> include unit in the aggregate key and show the unit from product/unit snapshot fallback.
- Date ranges may scan large sales and return tables -> perform aggregation in SQL and paginate the merged result; avoid per-row Eloquent loops for the main report.
- Quotation/order users may expect the sample's transaction-type filter -> make invoice-only scope explicit in the report/spec and leave transaction-type expansion as a later change.

## Migration Plan

No database migration is required.

Implementation can be deployed as application code only:
- Add route/controller/view/Livewire/service/export/test files.
- Change the Reports landing "Penjualan per produk" placeholder to a route-backed card.
- Rollback by removing the new route/component/service/export files and restoring the card to placeholder.

## Open Questions

- Should a later change add the detailed transaction-number/discount mode from the sample drawer?
- Should a later change include quotations and a true sales-order source once document semantics are defined?
- Should PDF export be added later for parity with the sample UI, or remain omitted like several existing report screens?
