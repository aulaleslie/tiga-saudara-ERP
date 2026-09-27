## ADDED Requirements

### Requirement: Sales order completion report access
The system SHALL provide a `Penyelesaian Pemesanan Penjualan` report page accessible only to users with `saleReports.access`.

#### Scenario: Authorized user opens sales order completion report
- **WHEN** a user with `saleReports.access` requests the sales order completion report route
- **THEN** the system displays the `Penyelesaian Pemesanan Penjualan` report page

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `saleReports.access` requests the sales order completion report route
- **THEN** the system returns 403

### Requirement: Source-stage filter
The report SHALL support a `Mulai dari` filter with values `Penawaran` and `Pemesanan`. `Penawaran` SHALL include local Sales drafts only. `Pemesanan` SHALL include Sales whose status is `WAITING_APPROVAL`, `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`. The default source-stage filter SHALL be `Pemesanan`.

#### Scenario: Penawaran includes drafts
- **WHEN** the report is filtered with `Mulai dari = Penawaran`
- **THEN** the result includes matching Sales with status `DRAFTED`
- **AND** the result excludes matching Sales with status `WAITING_APPROVAL` or later

#### Scenario: Pemesanan includes submitted and later sales
- **WHEN** the report is filtered with `Mulai dari = Pemesanan`
- **THEN** the result includes matching Sales with status `WAITING_APPROVAL`, `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`
- **AND** the result excludes matching Sales with status `DRAFTED`

#### Scenario: Default source stage is Pemesanan
- **WHEN** a user opens the report page for the first time
- **THEN** the pending filter state uses `Pemesanan` as the selected `Mulai dari` value

### Requirement: Report filters and snapshot behavior
The report SHALL support date range, period preset, customer multi-select, tag multi-select, and tag match logic filters. Results SHALL refresh only when the user applies filters, and exports SHALL use the last successfully applied filter snapshot.

#### Scenario: Date range filters by sale date
- **WHEN** a user applies a start date and end date
- **THEN** the report includes only selected-stage Sales whose `sales.date` falls within that date range

#### Scenario: Customer filter narrows rows
- **WHEN** the user selects one or more customers and applies filters
- **THEN** the report includes only selected-stage Sales belonging to those customers

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with `Mencakup semua`
- **THEN** the report includes only selected-stage Sales carrying every selected tag

#### Scenario: Tag any-match logic
- **WHEN** the user selects multiple tags with `Salah satu`
- **THEN** the report includes selected-stage Sales carrying at least one selected tag

#### Scenario: Export blocked after filter drift
- **WHEN** a user changes filters after the last successful Filter action
- **THEN** the system refuses export until the user applies the filters again

### Requirement: Summary report columns
The report SHALL display one summary row per selected Sale with columns `Order Date`, `Nomor Pemesanan`, `Order Amount`, `Order Status`, `Delivery Amount`, `Invoice Amount`, and `Payment Amount`.

#### Scenario: Summary row displays sale identity and order amount
- **WHEN** a selected Sale appears in the report
- **THEN** `Order Date` shows the Sale date
- **AND** `Nomor Pemesanan` shows the Sale reference
- **AND** `Order Amount` shows the Sale total amount

#### Scenario: Empty result state
- **WHEN** applied filters match no Sales
- **THEN** the report shows an empty state instead of stale rows or totals

### Requirement: Delivery amount calculation
The report SHALL calculate `Delivery Amount` from approved `dispatches` and `dispatch_details` for the selected Sale, using only approved dispatch details and the existing sale delivery commercial amount derivation.

#### Scenario: Approved dispatch contributes delivery amount
- **WHEN** a selected Sale has an approved dispatch detail for quantity 4 and matching commercial unit amount 100000
- **THEN** the report shows `Delivery Amount` increased by 400000 for that Sale

#### Scenario: Pending and rejected dispatches excluded from delivery amount
- **WHEN** a selected Sale has pending or rejected dispatch details
- **THEN** those dispatch details do not contribute to `Delivery Amount`

#### Scenario: Sale with no approved dispatch has zero delivery amount
- **WHEN** a selected Sale has no approved dispatch details
- **THEN** the report shows `Delivery Amount` as zero

### Requirement: Invoice amount calculation
The report SHALL calculate `Invoice Amount` from the selected Sale's total amount only when the Sale is approved or later. Sales with status `DRAFTED` or `WAITING_APPROVAL` SHALL show zero invoice amount.

#### Scenario: Draft sale has zero invoice amount
- **WHEN** a selected Sale has status `DRAFTED`
- **THEN** the report shows `Invoice Amount` as zero

#### Scenario: Waiting approval sale has zero invoice amount
- **WHEN** a selected Sale has status `WAITING_APPROVAL`
- **THEN** the report shows `Invoice Amount` as zero

#### Scenario: Approved sale has invoice amount
- **WHEN** a selected Sale has status `APPROVED`
- **THEN** the report shows `Invoice Amount` equal to the Sale total amount

#### Scenario: Dispatched sale has invoice amount
- **WHEN** a selected Sale has status `DISPATCHED` or `DISPATCHED PARTIALLY`
- **THEN** the report shows `Invoice Amount` equal to the Sale total amount

### Requirement: Payment amount calculation
The report SHALL calculate `Payment Amount` from active Sale payment rows first, then fall back to Sale header payment values when no active payment rows are present.

#### Scenario: Active payment rows define payment amount
- **WHEN** a selected Sale has active Sale payments totaling 300000
- **THEN** the report shows `Payment Amount` as 300000

#### Scenario: Invalidated payment rows are excluded
- **WHEN** a selected Sale has invalidated Sale payments
- **THEN** those payment rows do not contribute to `Payment Amount`

#### Scenario: Header fallback is used when no payment rows exist
- **WHEN** a selected Sale has no active Sale payment rows and has `paid_amount` greater than zero
- **THEN** the report shows `Payment Amount` from the Sale header fallback

### Requirement: Order status mapping
The report SHALL derive `Order Status` from local lifecycle and payment state using sample-facing labels. A selected Sale with no payment amount SHALL display `Belum Dibayar`. A selected Sale with payment amount greater than zero and less than invoice amount SHALL display `Terbayar Sebagian`. A selected Sale with invoice amount greater than zero and payment amount greater than or equal to invoice amount SHALL display `Selesai`.

#### Scenario: Unpaid sale displays Belum Dibayar
- **WHEN** a selected Sale has `Payment Amount` equal to zero
- **THEN** the report displays `Order Status` as `Belum Dibayar`

#### Scenario: Partially paid sale displays Terbayar Sebagian
- **WHEN** a selected Sale has `Payment Amount` greater than zero and less than `Invoice Amount`
- **THEN** the report displays `Order Status` as `Terbayar Sebagian`

#### Scenario: Fully paid approved sale displays Selesai
- **WHEN** a selected Sale has `Invoice Amount` greater than zero
- **AND** `Payment Amount` is greater than or equal to `Invoice Amount`
- **THEN** the report displays `Order Status` as `Selesai`

### Requirement: Export sales order completion report
The report SHALL allow authorized users to export the last successfully applied report result to XLSX and CSV. CSV exports SHALL contain only the table header and data rows. XLSX exports SHALL include company name, report title, selected date range, currency metadata, table rows, and a total row.

#### Scenario: CSV export uses plain table shape
- **WHEN** a user exports the report as CSV after applying filters
- **THEN** the CSV starts with the table header row
- **AND** the CSV contains one row per exported Sale
- **AND** the CSV does not include report metadata rows or a total row

#### Scenario: XLSX export includes metadata and total
- **WHEN** a user exports the report as XLSX after applying filters
- **THEN** the workbook includes company name, report title `sales_order_completion`, selected date range, and `(dalam IDR)` metadata above the table
- **AND** the workbook includes a final `Total` row summing order amount, delivery amount, invoice amount, and payment amount

#### Scenario: Export blocked before applying filters
- **WHEN** a user requests export before applying filters
- **THEN** the system refuses the export and asks the user to apply filters first

### Requirement: Tenant-scoped report data
The report SHALL scope data to the active `setting_id`.

#### Scenario: Other tenant sales are excluded
- **WHEN** the report is opened under one active setting
- **THEN** Sales belonging to another setting are excluded from the report and exports
