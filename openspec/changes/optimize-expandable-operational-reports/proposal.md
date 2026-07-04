## Why

Buku Besar, Detail Persediaan Barang, and Nilai Persediaan Barang currently build full transaction detail sets before rendering, so wide date ranges or high-volume products force expensive report generation even when users only need summaries. Inventory valuation also reconstructs purchase unit cost from `price * quantity`, which can diverge from the purchase approval and sales cost snapshot rules that use tax-exclusive line DPP.

## What Changes

- Render Buku Besar initially as bucket summaries with expandable detail sections.
- Load Buku Besar movement rows only when a user expands a bucket for the active filters.
- Render inventory detail and inventory valuation reports initially as product summaries with expandable detail sections.
- Load per-product inventory transaction rows only when a user expands that product for the active filters.
- Preserve full-detail XLSX/CSV exports for the active filters, independent of collapsed on-screen state.
- Align inventory valuation purchase cost replay with the existing purchase cost helper rule: line DPP from `sub_total - product_tax_amount`, with line discounts treated according to stored `sub_total`.
- Explicitly keep document-level purchase shipping and document-level purchase discount out of inventory average cost unless a separate landed-cost allocation change defines otherwise.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `operational-general-ledger-report`: add summary-first rendering and lazy bucket detail loading while preserving export detail.
- `inventory-detail-report`: add summary-first rendering and lazy per-product transaction detail loading.
- `inventory-valuation-report`: add summary-first rendering, lazy per-product valuation rows, and purchase cost replay correction.

## Impact

- Affected Livewire components:
  - `app/Livewire/Reports/OperationalGeneralLedgerReport.php`
  - `app/Livewire/Reports/InventoryDetailReport.php`
  - `app/Livewire/Reports/InventoryValuationReport.php`
- Affected report views:
  - `resources/views/livewire/reports/operational-general-ledger-report.blade.php`
  - `resources/views/livewire/reports/inventory-detail-report.blade.php`
  - `resources/views/livewire/reports/inventory-valuation-report.blade.php`
- Affected report services:
  - `app/Services/Reports/OperationalGeneralLedgerReportService.php`
  - `app/Services/Reports/OperationalMovementEventService.php`
  - `app/Services/Reports/InventoryDetailReportQueryService.php`
  - `app/Services/Reports/InventoryValuationReportQueryService.php`
  - `app/Services/Reports/InventorySummaryReportQueryService.php`
  - `app/Services/Reports/Concerns/InventoryReplaySupport.php`
- Export behavior remains full-detail for the selected filters.
- Tests should cover summary-first rendering, expansion loading, filter cache invalidation, export parity, and corrected purchase DPP cost replay.
