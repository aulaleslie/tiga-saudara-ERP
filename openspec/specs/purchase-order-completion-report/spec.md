# purchase-order-completion-report Specification

## Purpose
TBD - created by archiving change add-purchase-order-completion-report. Update Purpose after archive.
## Requirements
### Requirement: Purchase order completion report entry point
The system SHALL provide a `Penyelesaian Pemesanan Pembelian` report reachable from the Reports module and gated by `purchaseReports.access`.

#### Scenario: Authorized user opens the report
- **WHEN** a user with `purchaseReports.access` requests the purchase order completion report route
- **THEN** the system displays the `Penyelesaian Pemesanan Pembelian` report page
- **AND** the page shows `(dalam IDR)` as the report currency label

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `purchaseReports.access` requests the purchase order completion report route
- **THEN** the system returns 403

#### Scenario: Report is read-only
- **WHEN** a user views, filters, sorts, paginates, or exports the purchase order completion report
- **THEN** the system MUST NOT create, update, approve, reject, receive, pay, invalidate, archive, or delete purchase lifecycle records

### Requirement: Purchase order completion summary rows
The report SHALL render one summary row per purchase header matching the applied filters.

#### Scenario: Required columns are displayed
- **WHEN** a user applies filters and matching purchase rows exist
- **THEN** the table includes `Tanggal Pemesanan`
- **AND** the table includes `No. Pemesanan`
- **AND** the table includes `Jumlah Pemesanan`
- **AND** the table includes `Status Pemesanan`
- **AND** the table includes `Jumlah Pengiriman`
- **AND** the table includes `Jumlah Faktur`
- **AND** the table includes `Jumlah Pembayaran`

#### Scenario: Purchase with multiple details appears once
- **WHEN** a purchase has multiple purchase detail rows and matches the applied filters
- **THEN** the report displays one summary row for that purchase
- **AND** product detail rows are not rendered in the summary table

#### Scenario: Missing optional source values display safely
- **WHEN** a source value needed for a display column is unavailable
- **THEN** the report displays an empty value or `-`
- **AND** the system MUST NOT fabricate source data

### Requirement: Purchase date and source-stage filtering
The report SHALL filter purchase summary rows by `purchases.date`, active setting, and source-stage status group.

#### Scenario: Purchase date controls inclusion
- **WHEN** a purchase date is inside the selected report period
- **THEN** the purchase is eligible for inclusion

#### Scenario: Purchase outside selected period is excluded
- **WHEN** a purchase date is outside the selected report period
- **THEN** the purchase is not included even if related receiving or payment dates are inside the period

#### Scenario: Report is scoped to active setting
- **WHEN** another setting has purchases inside the selected report period
- **THEN** those purchases are not included in the active setting report

#### Scenario: Penawaran source stage includes draft purchases
- **WHEN** the user applies `Mulai dari` as `Penawaran`
- **THEN** the report includes matching purchases with status `DRAFTED`
- **AND** the report excludes non-draft purchase statuses from that source-stage selection

#### Scenario: Pemesanan source stage includes active purchase order lifecycle rows
- **WHEN** the user applies `Mulai dari` as `Pemesanan`
- **THEN** the report includes matching purchases with statuses `WAITING_APPROVAL`, `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, or `RETURNED`
- **AND** the report excludes `DRAFTED` purchases from that source-stage selection

### Requirement: Purchase completion amount calculation
The report SHALL calculate order amount, receiving amount, invoice amount, and payment amount from existing purchase, receiving, and payment records.

#### Scenario: Order amount uses purchase total
- **WHEN** a purchase appears in the report
- **THEN** `Jumlah Pemesanan` equals the purchase `total_amount`

#### Scenario: Receiving amount uses approved receiving notes
- **WHEN** a purchase has approved receiving note details
- **THEN** `Jumlah Pengiriman` equals the sum of approved received quantities valued proportionally from their related purchase detail commercial amounts

#### Scenario: Pending and rejected receiving notes are excluded
- **WHEN** a purchase has pending or rejected receiving note details
- **THEN** those details do not contribute to `Jumlah Pengiriman`

#### Scenario: Zero purchase quantity is safe
- **WHEN** a received detail references a purchase detail whose ordered quantity is zero
- **THEN** that received detail contributes zero receiving amount instead of causing a division error

#### Scenario: Invoice amount is zero for unapproved source rows
- **WHEN** a matching purchase has status `DRAFTED` or `WAITING_APPROVAL`
- **THEN** `Jumlah Faktur` is zero

#### Scenario: Invoice amount uses purchase total after approval
- **WHEN** a matching purchase has a status other than `DRAFTED` or `WAITING_APPROVAL`
- **THEN** `Jumlah Faktur` equals the purchase `total_amount`

#### Scenario: Payment amount uses active purchase payments
- **WHEN** a purchase has active purchase payment rows
- **THEN** `Jumlah Pembayaran` equals the sum of active purchase payment amounts
- **AND** invalidated purchase payments do not contribute

#### Scenario: Legacy payment fallback is preserved
- **WHEN** a purchase has no purchase payment rows but has persisted purchase header payment values
- **THEN** the report derives `Jumlah Pembayaran` using the same effective-payment fallback semantics as existing purchase reports

### Requirement: Purchase completion status labels
The report SHALL derive `Status Pemesanan` from purchase lifecycle status, invoice amount, and effective payment amount.

#### Scenario: Draft and waiting approval are unpaid
- **WHEN** a purchase has status `DRAFTED` or `WAITING_APPROVAL`
- **THEN** `Status Pemesanan` is `Belum Dibayar`

#### Scenario: No payment is unpaid
- **WHEN** a purchase has no effective payment amount
- **THEN** `Status Pemesanan` is `Belum Dibayar`

#### Scenario: Partial payment is partially paid
- **WHEN** a purchase has effective payment amount greater than zero and less than the invoice amount
- **THEN** `Status Pemesanan` is `Terbayar Sebagian`

#### Scenario: Full payment is complete
- **WHEN** a purchase has invoice amount greater than zero and effective payment amount greater than or equal to the invoice amount
- **THEN** `Status Pemesanan` is `Selesai`

### Requirement: Purchase order completion filters and sorting
The report SHALL support date range, period preset, source-stage, supplier, and purchase tag filters. The report SHALL support tag matching with `Salah satu` and `Mencakup semua` logic, and sorting by purchase date, order number, supplier name, and order amount.

#### Scenario: Date range validation
- **WHEN** the user applies filters with `Tanggal akhir` before `Tanggal awal`
- **THEN** the system rejects the filters with a Bahasa Indonesia validation message

#### Scenario: Period preset updates date range
- **WHEN** the user selects a period preset such as today, this month, this quarter, this year, previous month, or previous year
- **THEN** the pending `Tanggal awal` and `Tanggal akhir` values reflect that preset before filters are applied

#### Scenario: Supplier filter narrows rows
- **WHEN** the user selects one or more suppliers and applies filters
- **THEN** only purchases for those suppliers are included

#### Scenario: Tag filter with Salah satu includes any matching tag
- **WHEN** the user selects multiple purchase tags with `Salah satu`
- **THEN** purchases with any selected tag are included

#### Scenario: Tag filter with Mencakup semua requires all selected tags
- **WHEN** the user selects multiple purchase tags with `Mencakup semua`
- **THEN** only purchases containing every selected tag are included

#### Scenario: Sorting is deterministic
- **WHEN** the user sorts the report by an allowed field
- **THEN** rows are ordered by the selected field and direction
- **AND** ties are resolved by purchase id in descending order

### Requirement: Purchase order completion display
The report SHALL display results only after filters are applied, show an empty state when no rows match, and show totals for monetary amount columns when rows exist.

#### Scenario: Initial state prompts filtering
- **WHEN** the user opens the report before applying filters
- **THEN** the report prompts the user in Bahasa Indonesia to set filters and click `Filter`

#### Scenario: Empty state is shown
- **WHEN** applied filters match no purchase rows
- **THEN** the report displays an empty state message in Bahasa Indonesia

#### Scenario: Totals row is shown
- **WHEN** applied filters match one or more purchase rows
- **THEN** the report shows a totals row for `Jumlah Pemesanan`, `Jumlah Pengiriman`, `Jumlah Faktur`, and `Jumlah Pembayaran`

#### Scenario: Amounts use Indonesian display formatting
- **WHEN** the report displays monetary amounts
- **THEN** amounts are formatted for Indonesian IDR display with period thousands separators and no visible floating precision artifacts

### Requirement: Purchase order completion export
The report SHALL support snapshot-validated Excel and CSV exports for all rows matching the last applied filters.

#### Scenario: Export blocked before applying filters
- **WHEN** the user attempts to export before applying filters
- **THEN** the system refuses export and asks the user to apply filters first

#### Scenario: Export blocked after pending filter changes
- **WHEN** the user changes filters after applying them
- **THEN** the system refuses export until the filters are applied again

#### Scenario: Export includes all matching rows
- **WHEN** the user exports after applying filters
- **THEN** the export includes every matching row regardless of pagination

#### Scenario: XLSX includes metadata rows and total row
- **WHEN** the user exports Excel
- **THEN** the XLSX includes company name, `purchase_order_completion`, selected date range, and `(dalam IDR)` metadata rows above the table
- **AND** the XLSX includes a total row when exported rows exist

#### Scenario: CSV omits metadata and total row
- **WHEN** the user exports CSV
- **THEN** the CSV contains the table headings and data rows without XLSX metadata rows
- **AND** the CSV does not include the XLSX total row

#### Scenario: CSV numeric values are rounded
- **WHEN** the user exports CSV
- **THEN** monetary numeric values are emitted with two decimal places
- **AND** the CSV MUST NOT expose floating precision artifacts such as `20055000.000002`

### Requirement: First-scope exclusions
The first-scope purchase order completion report SHALL NOT implement PDF export, the `Order Completion Detail` template, global cross-setting reporting, or database schema changes.

#### Scenario: PDF export is not implemented
- **WHEN** export controls are rendered for the first-scope report
- **THEN** PDF export is absent or unavailable

#### Scenario: Detail template is not implemented
- **WHEN** the report is rendered
- **THEN** it does not provide the `Order Completion Detail` template as an implemented mode

#### Scenario: No schema changes are required
- **WHEN** the change is implemented
- **THEN** no new database migration is required for the report

