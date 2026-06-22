## Why

The Reports module already advertises `Pengiriman pembelian`, but it is still a placeholder while the business has Mekari/Jurnal sample exports showing the expected purchase delivery report shape. Users need a first-class ERP report for approved purchase receiving activity so supplier-delivered products can be reviewed and exported without leaving the system.

## What Changes

- Add a `Pengiriman pembelian` report under `Laporan -> Pembelian`, gated by `purchaseReports.access`.
- Source report rows from approved purchase receiving notes and receiving details, using receiving date as the report date basis.
- Group rows by supplier and show sample-aligned columns: supplier/product code context, product name, unit, received quantity, supplier subtotal, and grand total.
- Add report filters for date range, period presets, supplier, tag, product category, tag/category match logic, and sorting by supplier, purchase delivery, or product.
- Add Excel and CSV exports that match the applied report data and are guarded by the same snapshot pattern used by existing report exports.
- Keep the feature read-only: no purchase, receiving, stock, serial, payment, or historical data rewrite.

## Capabilities

### New Capabilities
- `purchase-delivery-report`: Purchase delivery report behavior, filters, grouping, calculations, permissions, and exports.

### Modified Capabilities
- `reports-landing-navigation`: The existing `Pengiriman pembelian` purchase report card changes from placeholder to actionable report navigation.

## Impact

- Affected code areas: `Modules/Reports` routes/controllers/views, `Modules/Reports/Http/Controllers/ReportsController`, `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and report feature tests.
- Data sources: `received_notes`, `received_note_details`, `purchases`, `purchase_details`, `suppliers`, `products`, `units`, `categories`, and purchase tags.
- Permissions: reuse `purchaseReports.access`; no new permission is required unless implementation discovers an existing report-specific convention that should be followed.
- Exports: new Excel/CSV export classes or equivalent report export path; PDF remains out of scope unless explicitly added later.
- Database schema: no new migrations expected; implementation should use existing purchase receiving schema.
