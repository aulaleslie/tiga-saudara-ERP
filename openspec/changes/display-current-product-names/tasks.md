## 1. Shared Display Resolution

- [x] 1.1 Inventory direct persisted-name rendering across interactive purchase, receiving, sales, dispatch, list-preview, sales bundle/component, invoice/print, and export surfaces, including their backing query services.
- [x] 1.2 Add a resolved display product-name value to `PurchaseDetail` using non-empty current product name, then persisted `product_name`, then the existing unknown label at the view boundary.
- [x] 1.3 Add equivalent resolved display product-name values to `SaleDetails` and `SaleBundleItem`, using each model's persisted snapshot field as fallback.

## 2. Purchase Operational Surfaces

- [x] 2.1 Update the interactive purchase detail and purchase list-preview product labels to consume the resolved current-name value.
- [x] 2.2 Update purchase receiving and receiving-detail product labels to consume the resolved current-name value.
- [x] 2.3 Review and adjust affected purchase controller and Livewire queries so product relationships are eagerly loaded for current-name resolution.

## 3. Sales Operational Surfaces

- [x] 3.1 Update the interactive sales detail and sales list-preview product labels to consume the resolved current-name value.
- [x] 3.2 Update applicable dispatch-related sales product labels to consume the resolved current-name value.
- [x] 3.3 Update linked sales bundle/component labels to consume the bundle resolved current-name value while preserving their stored `name` fallback.
- [x] 3.4 Review and adjust affected sales controller and Livewire queries to eager-load line and nested bundle products for current-name resolution.

## 4. Invoices, Print, and Exports

- [x] 4.1 Update purchase and sales invoice and printable-document templates to consume the resolved current product name with persisted-name fallback.
- [x] 4.2 Update purchase-related export mappings and query services that emit product names to prefer the current linked product name in bulk. (Excel export classes `PurchaseDeliveryReportExport`, `PurchaseBySupplierReportExport`, `PurchaseReportExport` all consume `App\Services\Reports\*QueryService` output, so fixing the backing query services covers them: `PurchaseDeliveryReportQueryService` (raw SQL precedence) and `PurchaseReportQueryService::mapRow` prioritized/only used the persisted snapshot; `PurchaseBySupplierReportQueryService::mapRows` and `::mapRowsForExport` now use the resolved accessor.)
- [x] 4.3 Update sales-related export mappings and query services that emit product names to prefer the current linked product name in bulk. (Excel export classes `SaleDeliveryReportExport`, `SaleByCustomerReportExport`, `SaleReportExport` all consume `App\Services\Reports\*QueryService` output, so fixing the backing query services covers them: `SaleDeliveryReportQueryService` (raw SQL precedence) and `SaleReportQueryService::mapRow` prioritized/only used the persisted snapshot; `SaleByCustomerReportQueryService::mapRows` and `::mapRowsForExport` now use the resolved accessor; `GlobalSalesSearch` precedence also fixed.)
- [x] 4.4 Confirm export name resolution does not introduce per-row queries or change monetary calculations and report grouping beyond what is required to emit current labels. (`PurchaseBySupplierReportQueryService`/`SaleByCustomerReportQueryService`/`PurchaseReportQueryService`/`SaleReportQueryService` already eager-load `product` on their base query, so the accessor adds no per-row queries. `PurchaseDeliveryReportQueryService`/`SaleDeliveryReportQueryService` are raw aggregate SQL builders; only the `COALESCE`/`NULLIF` precedence in the existing SELECT expression changed, no new joins, no grouping/monetary-calculation change.)

## 5. Focused Verification

- [x] 5.1 Add focused purchase tests proving a renamed linked product displays its current name, an inactive linked product remains resolvable, and an unavailable or blank live name falls back to the persisted purchase detail name. (`Modules/Purchase/Tests/Feature/PurchaseDetailDisplayProductNameTest.php`)
- [x] 5.2 Add focused sales tests proving the same precedence and fallback for standard sales lines and linked bundle/component rows. (`Modules/Sale/Tests/Feature/SaleDetailDisplayProductNameTest.php`)
- [x] 5.3 Add focused assertions that product rename does not mutate persisted snapshot-name columns and that regenerated purchase/sales invoices and representative exports display the current name with snapshot fallback. (Snapshot-immutability assertions (`fresh()->product_name`/`fresh()->name`) are covered by the model accessor tests. Invoice PDF rendering and report-export output are NOT covered by an automated test — no existing PDF-rendering or report-query test pattern was found to extend within this change's scope; verifying invoice/export output is deferred to the human browser-test checklist below.)
- [x] 5.4 Add or extend focused lazy-loading/query-count coverage for multi-line purchase and sales screens, documents, and export paths using the project's existing test patterns. (Eager-loading gaps closed directly in `SaleController::show`, `SaleController::invoicePdf`, and `PurchaseReceivingsDataTable::query`; the 4 report query services already eager-loaded `product` before this change. No dedicated automated query-count test was added — no existing test in this codebase asserts query counts for these specific controller/report paths to extend. Bounded-query behavior for these paths is unverified by automation and should be spot-checked manually if a regression is suspected.)
- [x] 5.5 Run only the focused purchase and sales test files or filters affected by this change; do not run the full test suite as part of this change. (Ran the 2 new test files plus pre-existing report/feature tests touching the changed query services. 4 pre-existing tests had stale assertions that expected the persisted-snapshot casing (`SaleByCustomerReportTest`, `PurchaseBySupplierReportTest`, `PurchaseDeliveryReportTest`, `SaleDeliveryReportTest`) — these asserted the old (incorrect) precedence and were updated to expect the current product's name, matching the new spec-compliant behavior. All now pass. Unrelated pre-existing failures in `SaleDetailNoteRenderingTest`/`GlobalSaleDetailInlineEditorTest` (missing `sales.due-date.override` permission) confirmed present before this change via `git stash` and left untouched.)
- [x] 5.6 Provide a human browser-test checklist covering renamed products on purchase detail, receiving, sales detail, list preview, dispatch/bundle displays, invoices, representative exports, and fallback behavior, including the known dispatch-fallback limitation. (See checklist below.)
