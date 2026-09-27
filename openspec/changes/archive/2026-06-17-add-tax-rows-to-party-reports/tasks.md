## 1. Test Coverage

- [x] 1.1 Add purchase-by-supplier tests proving a detail with `product_tax_amount > 0` renders a product row followed by a `Pajak` row and an untaxed detail renders no tax row.
- [x] 1.2 Add purchase-by-supplier tests proving running totals and page-two carry-over include `sub_total + product_tax_amount`.
- [x] 1.3 Add purchase-by-supplier export tests proving XLSX/CSV omit `Keterangan`, include expanded `Pajak` rows, and compute expanded running totals.
- [x] 1.4 Add sales-by-customer tests proving a detail with `product_tax_amount > 0` renders/exports a `Pajak` row and an untaxed detail does not.
- [x] 1.5 Add sales-by-customer tests proving running totals, total-based sorting, and page-two carry-over include tax amounts.
- [x] 1.6 Add export formatting assertions or row-array assertions for the reduced export column count in both report exports.

## 2. Shared Report Row Mapping

- [x] 2.1 Introduce a small report-row representation or helper methods that can map each purchase/sale detail into one product row and an optional `Pajak` row.
- [x] 2.2 Ensure the helper emits tax rows based only on `(float) product_tax_amount > 0`, regardless of `tax_id` or `settings.is_pkp`.
- [x] 2.3 Ensure product rows use `sub_total` for `Nominal tagihan` and tax rows use `product_tax_amount` for `Nominal tagihan`.
- [x] 2.4 Ensure tax rows reuse the source transaction date, transaction type, transaction number, route context, and group owner while setting quantity/unit/unit price to blank or zero-equivalent values consistent with existing display/export conventions.

## 3. Purchase Report UI

- [x] 3.1 Update `PurchaseBySupplierReportQueryService` total-sort aggregate to include `purchase_details.product_tax_amount`.
- [x] 3.2 Update `PurchaseBySupplierReport` prior-page running-total carry-over to sum `sub_total + product_tax_amount` for previous detail rows.
- [x] 3.3 Update displayed purchase rows so each paginated detail can render the product row and optional following `Pajak` row without changing paginator counts.
- [x] 3.4 Ensure supplier continuation and grouping still work when the first visible detail on a page expands into product and tax rows.

## 4. Purchase Report Export

- [x] 4.1 Update `PurchaseBySupplierReportExport` row generation to emit expanded product/tax rows in query order.
- [x] 4.2 Update purchase export running totals, supplier subtotal rows, and grand total rows to include tax rows.
- [x] 4.3 Remove `Keterangan` from purchase export headings and data rows for both XLSX and CSV.
- [x] 4.4 Update purchase XLSX formatting ranges, merged ranges, numeric column formats, and subtotal/grand-total style references for the reduced export column count.

## 5. Sales Report UI

- [x] 5.1 Update `SaleByCustomerReportQueryService` total-sort aggregate to include `sale_details.product_tax_amount`.
- [x] 5.2 Update `SaleByCustomerReport` prior-page running-total carry-over to sum `sub_total + product_tax_amount` for previous detail rows.
- [x] 5.3 Update displayed sales rows so each paginated detail can render the product row and optional following `Pajak` row without changing paginator counts.
- [x] 5.4 Ensure customer continuation and grouping still work when the first visible detail on a page expands into product and tax rows.

## 6. Sales Report Export

- [x] 6.1 Update `SaleByCustomerReportExport` row generation to emit expanded product/tax rows in query order.
- [x] 6.2 Update sales export running totals, customer subtotal rows, and grand total rows to include tax rows.
- [x] 6.3 Remove `Keterangan` from sales export headings and data rows for both XLSX and CSV.
- [x] 6.4 Update sales XLSX formatting ranges, merged ranges, numeric column formats, and subtotal/grand-total style references for the reduced export column count.

## 7. Verification

- [x] 7.1 Run focused purchase-by-supplier report tests.
- [x] 7.2 Run focused sales-by-customer report tests.
- [x] 7.3 Run `openspec validate add-tax-rows-to-party-reports --strict`.
- [x] 7.4 Review generated CSV/XLSX row arrays or fixture-style assertions against the with-tax sample shape.
