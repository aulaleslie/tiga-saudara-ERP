# Operational Balance Sheet Report Specification

## Purpose

Generate Neraca (balance sheet) reports for a single point in time, showing asset, liability, and equity balances derived from operational transaction data rather than chart-of-accounts journal balances. The report supports as-of date filtering scoped to the active tenant setting and exports to XLSX.
## Requirements
### Requirement: Reports landing exposes operational Neraca
The system SHALL expose the Neraca report as an available report card under Reports > Sekilas bisnis for users with `reports.access`.

#### Scenario: Authorized user opens Neraca from reports landing
- **WHEN** a user with `reports.access` views the Reports landing page
- **THEN** the Neraca card is shown as an actionable report link

#### Scenario: Unauthorized user cannot access Neraca
- **WHEN** a user without `reports.access` requests the Neraca report route
- **THEN** the system returns a forbidden response

### Requirement: Neraca report uses operational transaction buckets
The system SHALL generate the Neraca report from operational transaction data instead of chart-of-account journal balances.

#### Scenario: Report omits account numbers
- **WHEN** the Neraca report is rendered
- **THEN** the report table contains row names and amounts without a Nomor Akun column

#### Scenario: Report explains operational source
- **WHEN** the Neraca report is rendered or exported
- **THEN** the output includes a note that the report is calculated from operational transactions and does not yet use accounting journals

### Requirement: Neraca report supports as-of date filtering
The system SHALL calculate balances as of a selected date for the selected business source scope.

#### Scenario: Default as-of date is today
- **WHEN** the user opens the Neraca report without selecting a date
- **THEN** the report uses the current date as the as-of date

#### Scenario: Default business scope uses current setting
- **WHEN** the user opens the Neraca report without selecting any business source
- **THEN** the report calculates balances using only the current `session('setting_id')`

#### Scenario: User filters by as-of date
- **WHEN** the user selects an as-of date and applies the filter
- **THEN** the report includes only eligible operational activity dated on or before the selected date
- **AND** the report applies the effective selected business source scope to the as-of calculation

#### Scenario: User selects multiple business sources
- **WHEN** operational transactions exist for multiple settings and the user selects two or more business sources
- **THEN** the Neraca report includes eligible records whose `setting_id` is one of the selected settings
- **AND** records from unselected settings do not affect asset, liability, or equity totals

#### Scenario: Scope label is visible
- **WHEN** the Neraca report is rendered
- **THEN** the report header identifies the effective business source scope as the selected company name, `Semua Perusahaan`, or `Beberapa Perusahaan`

### Requirement: Neraca report calculates asset rows
The system SHALL present asset rows for cash/bank from transaction payments, customer receivables, inventory value, and other operational asset buckets supported by the available data within the selected business source scope.

#### Scenario: Paid sale increases cash or bank
- **WHEN** an eligible sale in the selected business source scope has a payment dated on or before the as-of date
- **THEN** the payment amount contributes to the cash/bank asset row

#### Scenario: Unpaid sale creates receivable
- **WHEN** an eligible sale in the selected business source scope has an outstanding due amount as of the selected date
- **THEN** the outstanding amount from the authoritative current sale document contributes to the customer receivables asset row
- **AND** completed sale return totals are not subtracted again from receivables when the sale document already reflects post-return values.

#### Scenario: Corrected sale after return is not double-subtracted
- **WHEN** an eligible sale in the selected business source scope has already been corrected for returned quantities and a completed sale return also exists
- **THEN** Neraca calculates customer receivables from the corrected sale document and payments
- **AND** it does not reduce receivables a second time from `sale_returns.total_amount`.

#### Scenario: Inventory value appears as asset
- **WHEN** stock-managed products have inventory value for selected business sources
- **THEN** the calculated inventory value from those selected settings contributes to the inventory asset row
- **AND** products from unselected settings do not affect the inventory asset row

### Requirement: Neraca report calculates liability rows
The system SHALL present liability rows for supplier payables, customer return obligations, tax liabilities when available, and other operational liability buckets supported by the available data.

#### Scenario: Unpaid purchase creates payable
- **WHEN** an eligible purchase has an outstanding due amount as of the selected date
- **THEN** the outstanding amount contributes to the supplier payables liability row

#### Scenario: Purchase payment reduces cash or bank
- **WHEN** an eligible purchase payment is dated on or before the as-of date
- **THEN** the payment amount reduces the cash/bank asset row

#### Scenario: Approved expense reduces cash or bank
- **WHEN** an approved, non-archived expense is dated on or before the as-of date
- **THEN** the expense amount reduces the cash/bank asset row

#### Scenario: Sale return refund reduces cash without double-reducing receivable
- **WHEN** a sale return refund payment is dated on or before the as-of date
- **THEN** the refund payment reduces cash or bank according to operational payment movement
- **AND** the report does not also subtract the sale return header from receivables when the current sale document is authoritative.

### Requirement: Neraca report derives equity to balance totals
The system SHALL derive equity as total assets minus total liabilities for the first operational version.

#### Scenario: Equity balances report totals
- **WHEN** the report has calculated total assets and total liabilities
- **THEN** the equity row equals total assets minus total liabilities

#### Scenario: Total liabilities and equity equals total assets
- **WHEN** the report is rendered
- **THEN** total liabilities plus equity equals total assets within currency rounding tolerance

### Requirement: Neraca report exports XLSX matching on-screen data
The system SHALL allow authorized users to export the filtered Neraca report to XLSX using the same calculation output and business source scope shown on screen.

#### Scenario: Export uses current filters
- **WHEN** the user exports the Neraca report after selecting an as-of date and business source scope
- **THEN** the XLSX file uses the same as-of date, selected business source scope, and report rows as the on-screen report

#### Scenario: Export includes report note
- **WHEN** the XLSX file is generated
- **THEN** it includes the operational-transaction source note

#### Scenario: Export labels selected scope
- **WHEN** the XLSX file is generated
- **THEN** the export header identifies the effective business source scope as the selected company name, `Semua Perusahaan`, or `Beberapa Perusahaan`

