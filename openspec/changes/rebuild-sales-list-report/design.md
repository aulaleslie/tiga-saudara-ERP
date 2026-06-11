## Context

`app/Livewire/Reports/PurchaseReport.php` is backed by a service stack — `PurchaseReportFilterData`, `PurchaseReportQueryService`, `PurchaseReportValidator`, `PurchaseReportSnapshot(Service)` — plus `PurchaseReportExport`, with a 400-line view supporting detail/header modes. The current `SaleReport` is a single inline-query component (~100 lines) with single-select filters and re-running exports. We rebuild `SaleReport` to mirror the purchase stack. The route (`reports.sale-report.index` / `.global`) and `SaleReportController` already exist and pass `isGlobal`.

## Goals / Non-Goals

**Goals:**
- Feature parity with `PurchaseReport`: detail/header modes, multi-select searchable customer/tag, multi document+payment status, presets, date basis, sorting, snapshot-validated export, global mode.
- A sales report service stack mirroring the purchase services for consistency and testability.

**Non-Goals:**
- No "per customer" report here (separate change `add-sales-by-customer-report`).
- No new permission; `saleReports.access` / `saleReports.global.access` are reused.
- No menu restructure here (the menu dropdown is introduced by the per-customer change, which is when a second sales report link appears).

## Decisions

- **Full rebuild over incremental upgrade.** The user chose parity. Replacing the component wholesale and adding the service layer is more work but yields a structure identical to purchases, easing maintenance. Alternative (bolting features onto the old component) was rejected as it would drift from the established pattern.
- **Port the purchase services 1:1 in shape, sales in semantics.** Mirror class responsibilities (FilterData/QueryService/Validator/Snapshot/SnapshotService) but query `Sale`/`sale_details`, customers via `customer_name`, and the dispatched status family.
- **Drop purchase-only columns.** There is no `supplier_purchase_number` analog; use `reference`. Sort fields mirror the purchase set minus supplier-purchase-number, plus customer_name in place of supplier_name.
- **Reuse the snapshot export contract.** `SaleReportExport` is upgraded to accept a built query + `SaleReportFilterData` + an isCsv flag, matching `PurchaseReportExport`, so export honors the same drift-rejection behavior.
- **Preserve route/controller.** Only the rendered Livewire component and its view/services change; URLs and gates are stable.

## Risks / Trade-offs

- [BREAKING change to `SaleReport` public API and `mount` signature] → The controller and view are updated in the same change; no other caller references the old contract (verified: only the reports route/view use it).
- [Status family mismatch copied from purchases] → Tests assert dispatched-family filtering and that received-only purchase statuses are absent.
- [Export drift bugs] → Mirror `PurchaseReportExportParityTest` to assert snapshot validation and column parity.
- [Larger surface = more to test] → Mitigated by porting the existing purchase test suite structure rather than authoring from scratch.
