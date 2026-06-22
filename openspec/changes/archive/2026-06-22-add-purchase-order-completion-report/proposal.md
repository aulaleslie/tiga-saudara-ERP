## Why

The Reports landing page already advertises `Penyelesaian pesanan pembelian`, but the card is still a placeholder. Users need the purchase-side counterpart to the existing sales-order completion report so they can review purchase order progress from ordering through receiving, invoicing, and payment in one summary.

## What Changes

- Add a `Penyelesaian Pemesanan Pembelian` report under the Pembelian reports category, gated by `purchaseReports.access`.
- Provide summary rows with `Tanggal Pemesanan`, `No. Pemesanan`, `Jumlah Pemesanan`, `Status Pemesanan`, `Jumlah Pengiriman`, `Jumlah Faktur`, and `Jumlah Pembayaran`.
- Support date range and period presets, source-stage filtering (`Pemesanan` / `Penawaran`), supplier multi-select, purchase tag multi-select, tag match logic, sorting, pagination, empty state, and filter validation.
- Calculate receiving amount from approved purchase receiving notes and active payment amount from active purchase payment records.
- Support snapshot-validated Excel and CSV exports matching the report sample shape, including XLSX metadata rows and CSV table-only output.
- Replace the current Reports landing placeholder for `Penyelesaian pesanan pembelian` with an actionable card and route.
- No database schema changes and no changes to purchase, receiving, or payment lifecycle behavior.

## Capabilities

### New Capabilities
- `purchase-order-completion-report`: Covers the purchase order completion report entry point, filters, calculations, display, and exports.

### Modified Capabilities
- `reports-landing-navigation`: The existing `Penyelesaian pesanan pembelian` card in the Pembelian tab becomes actionable instead of a placeholder.

## Impact

- Affected report code:
  - `Modules/Reports/Routes/web.php`
  - `Modules/Reports/Http/Controllers/*`
  - `Modules/Reports/Http/Controllers/ReportsController.php`
  - `Modules/Reports/Resources/views/*`
  - `app/Livewire/Reports/*`
  - `app/Services/Reports/*`
  - `app/Exports/*`
- Affected source data:
  - `purchases`
  - `purchase_details`
  - `purchase_payments`
  - `received_notes`
  - `received_note_details`
  - `suppliers`
  - purchase tags via Spatie taggables
- Verification impact:
  - Feature tests for authorized/unauthorized routing, filter semantics, calculations, export snapshot protection, and CSV/XLSX mapping.
  - Regression attention around existing sales completion, purchase delivery, and purchase list reports because the implementation should mirror their patterns without changing their behavior.
