# profit-loss-report-setting-scope Specification

## Purpose
TBD - created by archiving change add-profit-loss-report-setting-scope. Update Purpose after archive.
## Requirements
### Requirement: Profit/loss report supports selectable company scope
The system SHALL allow a user with `reports.access` to choose which companies/settings are included in the existing Laporan Laba Rugi report, and the selected scope SHALL apply to net sales, sales cost, approved expenses, and final profit/loss totals.

#### Scenario: Default scope uses current setting
- **WHEN** a permitted user opens `/profit-loss-report` without selecting any company scope
- **THEN** the report SHALL calculate totals using only the current `session('setting_id')`
- **AND** the report SHALL calculate net sales from scoped sales and sale returns
- **AND** the report SHALL calculate sales cost from scoped sale detail cost snapshots and scoped sale return cost reversals
- **AND** the report SHALL calculate Laba (Rugi) as net sales minus sales cost minus approved expenses

#### Scenario: User selects multiple settings
- **WHEN** a permitted user selects two or more settings in the Laporan Laba Rugi company scope filter and applies the report
- **THEN** the report SHALL calculate net sales, sales cost, expenses, and profit/loss using only records whose `setting_id` is one of the selected settings
- **AND** records from unselected settings SHALL NOT affect the totals

#### Scenario: User selects all settings
- **WHEN** a permitted user selects every available setting in the company scope filter and applies the report
- **THEN** the report SHALL calculate totals across all selected settings
- **AND** the report scope label SHALL indicate `Semua Perusahaan`

#### Scenario: Report presents sales cost instead of purchases as cost
- **WHEN** the report renders operational rows for a selected date range
- **THEN** the cost section SHALL include Beban Pokok Pendapatan or equivalent sales-cost wording based on sale detail cost snapshots
- **AND** completed purchases and purchase returns SHALL NOT be used as direct profit/loss cost rows

### Requirement: Profit/loss report export preserves selected company scope
The system SHALL export Laporan Laba Rugi using the same selected setting scope and sales-cost calculation as the screen report.

#### Scenario: Export uses selected settings
- **WHEN** a permitted user selects multiple settings and exports the Laporan Laba Rugi report
- **THEN** the Excel export SHALL calculate totals from the same selected setting IDs as the screen report
- **AND** the exported net sales, sales cost, expenses, and profit/loss subtotal values SHALL match the screen report for the same date range and setting scope

#### Scenario: Export labels selected scope
- **WHEN** a permitted user exports a Laporan Laba Rugi report for exactly one effective setting
- **THEN** the export header SHALL identify that setting's company name

#### Scenario: Export labels all-company scope
- **WHEN** a permitted user exports a Laporan Laba Rugi report after selecting every available setting
- **THEN** the export header SHALL identify the scope as `Semua Perusahaan`

#### Scenario: Export omits purchase-cost rows
- **WHEN** a permitted user exports the Laporan Laba Rugi report
- **THEN** the Excel file SHALL not present completed purchases or purchase returns as the report's profit/loss cost basis
- **AND** it SHALL present sales cost from sale detail cost snapshots instead

### Requirement: Multi-company profit/loss access uses reports access
The system SHALL allow any user with `reports.access` to use the Laporan Laba Rugi company scope selector without requiring an additional global-report permission.

#### Scenario: Reports access permits multi-company scope
- **WHEN** a user with `reports.access` visits `/profit-loss-report`
- **THEN** the user SHALL be able to select one or more settings for the report scope
- **AND** the system SHALL NOT require a separate global report permission for this selector

#### Scenario: Existing access denial remains unchanged
- **WHEN** a user without `reports.access` visits `/profit-loss-report`
- **THEN** the system SHALL deny access to the report page

