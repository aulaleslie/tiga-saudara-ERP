## Why

The Reports landing page already exposes a `Penyelesaian pesanan penjualan` card, but it is still a placeholder even though the business needs a Mekari-style sales order completion report for tracking quotation/draft, order approval, delivery, invoicing, and payment progress. The sample files under `report-sample/penyelesaian-pemesanan-penjualan/` define the expected UI filters and export shapes, and the existing Sales, Dispatch, Payment, and Reports stacks already contain the data and patterns needed to implement the report without schema changes.

## What Changes

- Add a `Penyelesaian Pemesanan Penjualan` report under Reports > Penjualan, gated by `saleReports.access`.
- Convert the existing Reports landing `Penyelesaian pesanan penjualan` placeholder into an actionable card.
- Add a report page with date range, period preset, `Mulai dari`, customer, tag, and tag logic filters based on the sample.
- Treat local Sales drafts as `Penawaran` and Sales submitted to approval or beyond as `Pemesanan`.
- Show summary rows with order date, sale reference, order amount, order status, delivery amount, invoice amount, and payment amount.
- Add snapshot-gated XLSX and CSV exports matching the sample's core behavior: CSV as a plain table and XLSX with metadata plus a total row.
- Reuse existing Reports module patterns for routes, controllers, Livewire, filter data, query service, snapshot service, validator, exports, and tests.

## Capabilities

### New Capabilities
- `sales-order-completion-report`: Provides the sales order completion report contract, including access, filters, lifecycle mapping, amount calculation, presentation, and exports.

### Modified Capabilities
- `reports-landing-navigation`: Updates the Penjualan tab so the `Penyelesaian pesanan penjualan` card links to the new report route instead of rendering as unavailable.

## Impact

- Affected Reports module files: routes, report controller/view, Reports landing card configuration, and report landing tests.
- New report implementation files under `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and `resources/views/livewire/reports`.
- New focused tests for access, landing navigation, filter semantics, amount derivation, snapshot-gated exports, CSV/XLSX shape, and tenant scoping.
- No database migration, historical data rewrite, new permission, or changes to Sales, Dispatch, Payment, Quotation, POS, or Sales Return lifecycle behavior.
