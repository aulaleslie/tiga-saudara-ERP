## ADDED Requirements

### Requirement: Aged payables report access
The system SHALL provide an `Usia utang` aged payables report page that is reachable from the Pembelian tab of the Reports landing and restricted to users holding `purchaseReports.access`.

#### Scenario: Authorized user opens aged payables report
- **WHEN** a user with `purchaseReports.access` requests the aged payables report route
- **THEN** the system renders the `Usia utang` / `Hutang` report page
- **AND** the report is scoped to the active tenant setting

#### Scenario: Unauthorized user is blocked
- **WHEN** a user without `purchaseReports.access` requests the aged payables report route
- **THEN** the system responds with HTTP 403
- **AND** the `Usia utang` report card is not shown on the landing for that user

### Requirement: As-of date filtering
The report SHALL use a required as-of date filter labeled `Per`. The report SHALL include only eligible purchases dated on or before the selected as-of date and SHALL compute payable balances using active payments dated on or before that date.

#### Scenario: Default as-of date is today
- **WHEN** a user opens the report page without applying filters
- **THEN** the as-of date input defaults to the current calendar date
- **AND** report rows are not displayed until the user applies the filter

#### Scenario: Future purchases are excluded
- **WHEN** the report is filtered for an as-of date
- **THEN** purchases dated after that as-of date are excluded from all payable totals

#### Scenario: Later payments are excluded
- **WHEN** a purchase has a payment dated after the selected as-of date
- **THEN** that payment is not subtracted from the purchase's outstanding payable in the report

#### Scenario: Active payment before as-of date is included
- **WHEN** a purchase has an active payment dated on or before the selected as-of date
- **THEN** that payment is subtracted from the purchase's outstanding payable in the report

#### Scenario: Invalidated payment is excluded
- **WHEN** a purchase payment has a non-active status
- **THEN** that payment is not subtracted from the purchase's outstanding payable in the report

### Requirement: Period presets
The report SHALL provide period presets for today, this week, this month, this year, yesterday, last week, last month, and last year. Selecting a preset SHALL update the pending as-of date to the preset's ending date and SHALL NOT refresh report results until the user applies the filter.

#### Scenario: This month preset updates pending as-of date
- **WHEN** a user selects the this month period preset
- **THEN** the pending as-of date is set to the last day of the current calendar month
- **AND** the displayed report rows do not change until the user applies the filter

#### Scenario: Last month preset updates pending as-of date
- **WHEN** a user selects the last month period preset
- **THEN** the pending as-of date is set to the last day of the previous calendar month
- **AND** the displayed report rows do not change until the user applies the filter

### Requirement: Vendor-level aging bucket rows
The report SHALL display one row per vendor with columns `Vendor`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`.

#### Scenario: Vendor row displays bucket totals
- **WHEN** a vendor has outstanding payable balances as of the selected date
- **THEN** the report displays one row for that vendor
- **AND** each bucket column contains that vendor's outstanding payable balance for the bucket
- **AND** the `Total` column equals the sum of the four bucket columns

#### Scenario: Vendor without outstanding balance is omitted
- **WHEN** a vendor has no positive rounded outstanding payable balance as of the selected date
- **THEN** that vendor is not displayed in the report

#### Scenario: Report includes grand total row
- **WHEN** the report contains one or more vendor rows
- **THEN** the report displays a `Total Hutang` grand total row
- **AND** each grand total value equals the sum of that column across all displayed vendor rows

### Requirement: Outstanding payable calculation
The report SHALL calculate each purchase's outstanding payable as the purchase total minus active purchase payments for that purchase dated on or before the selected as-of date. The report SHALL account for the existing purchase payment storage scale by using the same amount scaling as the invoice-detail supplier payables report. The report SHALL round balances to two decimals before positive-balance filtering and presentation.

#### Scenario: Purchase with partial payment contributes remaining balance
- **WHEN** a purchase total is 1000000 and active payments through the as-of date total 250000
- **THEN** the purchase contributes 750000 to the vendor's aged payable buckets

#### Scenario: Fully settled purchase is excluded
- **WHEN** a purchase's rounded outstanding payable is zero as of the selected date
- **THEN** that purchase does not contribute to any vendor total

#### Scenario: Tenant scoping prevents cross-setting rows
- **WHEN** another tenant has outstanding purchases for the same vendor name
- **THEN** those purchases do not contribute to the active tenant's aged payable report

### Requirement: Aging basis selection
The report SHALL support aging outstanding purchase balances by transaction date or due date. Transaction-date aging SHALL use the purchase transaction date. Due-date aging SHALL use the purchase due date, falling back to the transaction date when the purchase has no due date.

#### Scenario: Transaction-date basis assigns buckets from purchase date
- **WHEN** the aging basis is `Tanggal Transaksi`
- **THEN** each outstanding purchase balance is assigned to an aging bucket using the number of days between the as-of date and the purchase transaction date

#### Scenario: Due-date basis assigns buckets from due date
- **WHEN** the aging basis is `Tanggal Jatuh Tempo`
- **THEN** each outstanding purchase balance is assigned to an aging bucket using the number of days between the as-of date and the purchase due date

#### Scenario: Missing due date falls back to transaction date
- **WHEN** the aging basis is `Tanggal Jatuh Tempo`
- **AND** a matching outstanding purchase has no due date
- **THEN** the purchase balance is aged using the purchase transaction date

### Requirement: Aging bucket boundaries
The report SHALL assign outstanding balances to aging buckets using the day difference between the selected as-of date and the selected aging basis date.

#### Scenario: First bucket includes same-day through 30 days
- **WHEN** an outstanding purchase is dated from the as-of date through 30 days before the as-of date
- **THEN** its outstanding balance contributes to `1 - 30 Hari`

#### Scenario: Second bucket includes 31 through 60 days
- **WHEN** an outstanding purchase is dated 31 through 60 days before the as-of date
- **THEN** its outstanding balance contributes to `31 - 60 Hari`

#### Scenario: Third bucket includes 61 through 90 days
- **WHEN** an outstanding purchase is dated 61 through 90 days before the as-of date
- **THEN** its outstanding balance contributes to `61 - 90 Hari`

#### Scenario: Final bucket includes older than 90 days
- **WHEN** an outstanding purchase is dated more than 90 days before the as-of date
- **THEN** its outstanding balance contributes to `> 90 Hari`

### Requirement: Report filtering and sorting
The report SHALL support filtering by vendor multi-select and tag multi-select with any/all logic, and SHALL support sorting by vendor name or total outstanding payable balance.

#### Scenario: Vendor filter limits rows
- **WHEN** the user selects one or more vendors and applies the filter
- **THEN** the report includes only selected vendors that have positive rounded outstanding payable balances

#### Scenario: Tag filter with any logic
- **WHEN** the user selects tags with any logic
- **THEN** the report includes eligible purchases carrying at least one selected tag

#### Scenario: Tag filter with all logic
- **WHEN** the user selects tags with all logic
- **THEN** the report includes eligible purchases carrying every selected tag

#### Scenario: Sort by total balance
- **WHEN** the user sorts by total balance descending
- **THEN** vendor rows are ordered from highest total outstanding payable balance to lowest total outstanding payable balance

#### Scenario: Sort by vendor name
- **WHEN** the user sorts by vendor name ascending
- **THEN** vendor rows are ordered alphabetically by vendor name

### Requirement: Export aged payables report
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
- **THEN** the first row contains `Vendor`, `Total`, `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari`
- **AND** the CSV contains one row per exported vendor
- **AND** the CSV does not include report metadata rows before the header

#### Scenario: XLSX export includes metadata and grand total
- **WHEN** a user exports the report as XLSX after applying filters
- **THEN** the workbook includes company name, report title `Hutang`, selected as-of date, and `(dalam IDR)` metadata above the table header
- **AND** the workbook includes a `Total Hutang` grand total row

#### Scenario: PDF export matches filtered result
- **WHEN** a user exports the report as PDF after applying filters
- **THEN** the PDF export contains the same vendor rows, bucket totals, and grand total as the filtered report result
