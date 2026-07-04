## 1. Report Service Contracts

- [x] 1.1 Add summary-first and single-bucket detail entry points to `OperationalGeneralLedgerReportService` while preserving the existing full-detail export path.
- [x] 1.2 Refactor `OperationalMovementEventService` or its callers so Buku Besar detail loading can request rows for one bucket without hydrating all bucket rows into the Livewire view.
- [x] 1.3 Add summary-first and single-product detail entry points to `InventoryDetailReportQueryService` while preserving the existing full-detail export path.
- [x] 1.4 Add summary-first and single-product detail entry points to `InventoryValuationReportQueryService` while preserving the existing full-detail export path.
- [x] 1.5 Share filtering, date resolution, transaction ordering, delta resolution, and running-balance helpers between summary and detail paths to prevent summary/detail drift.

## 2. Inventory Cost Correctness

- [x] 2.1 Update inventory valuation purchase price map logic to calculate purchase unit cost from line DPP using `sub_total - product_tax_amount` divided by quantity.
- [x] 2.2 Ensure line-level discounts are not subtracted twice when stored `sub_total` already reflects the line discount.
- [x] 2.3 Ensure document-level purchase `shipping_amount` and document-level `discount_amount` are not allocated into inventory average cost in this change.
- [x] 2.4 Add focused unit tests for tax-included purchase DPP, discounted purchase line handling, and document-level shipping/discount exclusion.

## 3. Buku Besar Livewire UI

- [x] 3.1 Add expanded-bucket state and loaded bucket-row cache to `OperationalGeneralLedgerReport`.
- [x] 3.2 Add a Livewire action to toggle a Buku Besar bucket and load rows for that bucket using the current applied filters.
- [x] 3.3 Clear expanded bucket state and loaded rows whenever date range, bucket filter, or business source scope is applied/reset.
- [x] 3.4 Update the Buku Besar Blade view to render bucket summaries initially and movement rows only inside expanded bucket sections.
- [x] 3.5 Keep the Buku Besar XLSX export full-detail for all selected buckets regardless of expanded UI state.

## 4. Inventory Detail Livewire UI

- [x] 4.1 Add expanded-product state and loaded product-row cache to `InventoryDetailReport`.
- [x] 4.2 Add a Livewire action to toggle a product and load rows for that product using current applied filters and category/product scoping.
- [x] 4.3 Clear expanded product state and loaded rows whenever date range, category filter, or business source scope is applied/reset.
- [x] 4.4 Update the Inventory Detail Blade view to render product summaries initially and movement rows only inside expanded product sections.
- [x] 4.5 Keep the Inventory Detail XLSX export full-detail for all selected products regardless of expanded UI state.

## 5. Inventory Valuation Livewire UI

- [x] 5.1 Add expanded-product state and loaded valuation-row cache to `InventoryValuationReport`.
- [x] 5.2 Add a Livewire action to toggle a product and load that product's opening row, valuation ledger rows, and subtotal using the current applied filters.
- [x] 5.3 Clear expanded valuation state and loaded rows whenever date range, category filter, product filter, match mode, or sorting is applied/reset.
- [x] 5.4 Update the inventory valuation Blade view to render valuation summaries initially and valuation ledger rows only inside expanded product sections.
- [x] 5.5 Keep CSV and XLSX exports full-detail for all filtered products regardless of expanded UI state.

## 6. Verification

- [ ] 6.1 Add or update Buku Besar Livewire tests proving initial summary render, bucket expansion, filter cache invalidation, and full-detail export behavior.
- [ ] 6.2 Add or update inventory detail Livewire tests proving initial summary render, product expansion, filter cache invalidation, and full-detail export behavior.
- [ ] 6.3 Add or update inventory valuation Livewire tests proving initial summary render, product expansion, filter cache invalidation, full-detail export behavior, and corrected purchase DPP replay.
- [ ] 6.4 Run focused report service and Livewire tests for the changed report classes.
- [ ] 6.5 Run a broader PHP test command when practical, such as `php artisan test` with relevant filters or `composer test:fresh-sqlite`.
