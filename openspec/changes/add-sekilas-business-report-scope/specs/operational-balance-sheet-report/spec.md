## MODIFIED Requirements

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
