## ADDED Requirements

### Requirement: Aged receivables report access
The system SHALL provide an `Usia piutang` report page that is accessible only to users with `saleReports.access`.

#### Scenario: Authorized user opens aged receivables report
- **WHEN** a user with `saleReports.access` requests the aged receivables report route
- **THEN** the system renders the `Usia piutang` report page
- **AND** the page is scoped to the active tenant setting

#### Scenario: Unauthorized user is blocked
- **WHEN** a user without `saleReports.access` requests the aged receivables report route
- **THEN** the system responds with HTTP 403

### Requirement: As-of date filtering
The report SHALL use a required as-of date filter labeled as the report's `Per` date. The report SHALL include only sales dated on or before the selected as-of date.

#### Scenario: Default as-of date is today
- **WHEN** a user opens the report page without applying filters
- **THEN** the as-of date input defaults to the current calendar date

#### Scenario: Future sales are excluded
- **WHEN** the report is filtered for an as-of date
- **THEN** sales dated after that as-of date are excluded from all receivable totals

#### Scenario: Period preset updates as-of date
- **WHEN** a user selects a supported period preset such as today, this week, this month, or this year
- **THEN** the pending as-of date is updated to the preset's ending date
- **AND** report results are not refreshed until the user applies the filter

### Requirement: Customer-level aging bucket rows
The report SHALL display one row per customer with columns `Customer`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`.

#### Scenario: Customer row displays bucket totals
- **WHEN** a customer has outstanding receivable balances as of the selected date
- **THEN** the report displays one row for that customer
- **AND** each bucket column contains that customer's outstanding balance for the bucket
- **AND** the `Total` column equals the sum of the four bucket columns

#### Scenario: Customer without outstanding balance is omitted
- **WHEN** a customer has no positive rounded outstanding receivable balance as of the selected date
- **THEN** that customer is not displayed in the report

#### Scenario: Report includes subtotal row
- **WHEN** the report contains one or more customer rows
- **THEN** the report displays a `Total Piutang (semua pelanggan)` subtotal row
- **AND** each subtotal value equals the sum of that column across all displayed customer rows

### Requirement: Outstanding balance calculation
The report SHALL calculate each sale's outstanding balance as the sale total minus active sale payments for that sale dated on or before the selected as-of date. The report SHALL round balances to two decimals before positive-balance filtering and presentation.

#### Scenario: Later payment is excluded
- **WHEN** a sale has a payment dated after the selected as-of date
- **THEN** that payment is not subtracted from the sale's outstanding balance in the report

#### Scenario: Active payment before as-of date is included
- **WHEN** a sale has an active payment dated on or before the selected as-of date
- **THEN** that payment is subtracted from the sale's outstanding balance in the report

#### Scenario: Invalidated payment is excluded
- **WHEN** a sale payment has a non-active status
- **THEN** that payment is not subtracted from the sale's outstanding balance in the report

### Requirement: Transaction-date aging buckets
The report SHALL assign outstanding sale balances to aging buckets using the number of days between the selected as-of date and the sale transaction date.

#### Scenario: First bucket includes same-day through 30 days
- **WHEN** an outstanding sale is dated from the as-of date through 30 days before the as-of date
- **THEN** its outstanding balance contributes to `1 - 30 Hari`

#### Scenario: Second bucket includes 31 through 60 days
- **WHEN** an outstanding sale is dated 31 through 60 days before the as-of date
- **THEN** its outstanding balance contributes to `31 - 60 Hari`

#### Scenario: Third bucket includes 61 through 90 days
- **WHEN** an outstanding sale is dated 61 through 90 days before the as-of date
- **THEN** its outstanding balance contributes to `61 - 90 Hari`

#### Scenario: Final bucket includes older than 90 days
- **WHEN** an outstanding sale is dated more than 90 days before the as-of date
- **THEN** its outstanding balance contributes to `> 90 Hari`

### Requirement: Report filtering and sorting
The report SHALL support filtering by customer multi-select and tag multi-select with any/all logic, and SHALL support sorting by customer name or total outstanding balance.

#### Scenario: Customer filter limits rows
- **WHEN** the user selects one or more customers and applies the filter
- **THEN** the report includes only selected customers that have positive rounded outstanding balances

#### Scenario: Tag filter limits eligible sales
- **WHEN** the user selects tags with any logic
- **THEN** the report includes eligible sales carrying at least one selected tag
- **WHEN** the user selects tags with all logic
- **THEN** the report includes eligible sales carrying every selected tag

#### Scenario: Sort by total balance
- **WHEN** the user sorts by total balance descending
- **THEN** customer rows are ordered from highest total outstanding balance to lowest total outstanding balance

#### Scenario: Sort by customer name
- **WHEN** the user sorts by customer name ascending
- **THEN** customer rows are ordered alphabetically by customer name

### Requirement: Export aged receivables report
The report SHALL allow authorized users to export the last successfully applied report result to XLSX, CSV, and PDF. Exports SHALL be blocked until filters have been applied and SHALL be blocked when pending filters no longer match the last applied snapshot.

#### Scenario: Export blocked before filter
- **WHEN** a user opens the report and requests an export before applying filters
- **THEN** the system does not generate an export
- **AND** the user is notified that filters must be applied before export

#### Scenario: Export blocked after unapplied filter changes
- **WHEN** a user applies filters, then changes filter inputs without applying them
- **THEN** export is blocked for the stale snapshot

#### Scenario: CSV export uses plain table shape
- **WHEN** a user exports the report as CSV after applying filters
- **THEN** the first row contains `Customer`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`
- **AND** the CSV contains one row per exported customer
- **AND** the CSV does not include report metadata rows before the header

#### Scenario: XLSX export includes metadata and subtotal
- **WHEN** a user exports the report as XLSX after applying filters
- **THEN** the workbook includes company name, report title `Piutang`, selected as-of date, and `(dalam IDR)` metadata above the table header
- **AND** the workbook includes a `Total Piutang (semua pelanggan)` subtotal row

#### Scenario: PDF export matches filtered result
- **WHEN** a user exports the report as PDF after applying filters
- **THEN** the PDF export contains the same customer rows, bucket totals, and report subtotal as the filtered report result
