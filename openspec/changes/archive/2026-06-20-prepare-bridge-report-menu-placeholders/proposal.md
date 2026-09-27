## Why

The Reports landing page currently exposes only implemented local ERP reports, while the bridge samples under `report-sample/bridge` define a broader target report menu taxonomy. Preparing the menu now gives users and implementers a clear roadmap without changing existing report behavior or adding report logic prematurely.

## What Changes

- Append bridge-derived report menu cards to the existing `/reports` landing page for Sekilas bisnis, Penjualan, Pembelian, Produk, and Pajak.
- Keep all existing implemented report cards, routes, labels, and behavior unchanged.
- Render bridge-derived cards for reports that are not implemented yet as visible disabled placeholders with a `Belum tersedia` status instead of links.
- Exclude Bank and Aset intentionally from this change.
- Exclude Produksi because there is no corresponding `report-sample/bridge/produksi.txt` sample in scope.
- Exclude `Jurnal`, `Perubahan Modal`, and `Ringkasan Bisnis` from Sekilas bisnis as requested.
- Add tests for appended menu cards, disabled placeholder behavior, permission filtering, and excluded cards.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `reports-landing-navigation`: Extend the reports landing page menu to support bridge-derived placeholder cards while preserving existing clickable report cards.

## Impact

- Affected code:
  - `Modules/Reports/Http/Controllers/ReportsController.php`
  - `Modules/Reports/Resources/views/index.blade.php`
  - `Modules/Reports/Tests/Feature/ReportsLandingTest.php`
- Inputs/reference:
  - `report-sample/bridge/sekilas-bisnis.txt`
  - `report-sample/bridge/penjualan.txt`
  - `report-sample/bridge/pembelian.txt`
  - `report-sample/bridge/produk.txt`
  - `report-sample/bridge/pajak.txt`
- No database changes.
- No new report controllers, Livewire components, queries, exports, or report routes.
- No changes to existing report pages or exports.
