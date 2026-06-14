## ADDED Requirements

### Requirement: Operational profit/loss calculation

The system SHALL calculate Laporan Laba Rugi as an operational period report using only completed sales, completed sale returns, completed purchases, completed purchase returns, and approved non-archived expenses in the selected date range.

#### Scenario: Report calculates profit from operational totals

- **WHEN** a permitted user filters the report for a date range containing completed sales, completed sale returns, completed purchases, completed purchase returns, and approved non-archived expenses
- **THEN** the report calculates `Total Pendapatan Bersih` as completed sales total minus completed sale returns total
- **AND** the report calculates `Total Biaya` as completed purchases total minus completed purchase returns total plus approved non-archived expenses total
- **AND** the report calculates `Laba (Rugi)` as `Total Pendapatan Bersih` minus `Total Biaya`

#### Scenario: Report uses transaction dates

- **WHEN** a return is recorded inside the selected date range for an original sale or purchase outside the selected date range
- **THEN** the return amount is included in the selected period based on the return date
- **AND** the original transaction is not included unless its own transaction date is also inside the selected period

#### Scenario: Report excludes payment metrics

- **WHEN** the report is rendered
- **THEN** payment received, payment sent, and payment net totals are not included as report rows, cards, or profit/loss inputs

#### Scenario: Report excludes accounting ledger data

- **WHEN** chart-of-account or journal entries exist in the selected date range
- **THEN** the operational profit/loss totals are determined from sales, sale returns, purchases, purchase returns, and expenses
- **AND** chart-of-account rows, journal rows, account codes, and account drill-down links are not required to render the report

### Requirement: Jurnal-style operational report UI

The system SHALL replace the current profit/loss summary-card dashboard with a Jurnal-style tabular report adapted from `prompt.txt` while using operational labels and totals.

#### Scenario: Report renders prompt-adapted header and filters

- **WHEN** a permitted user opens the profit/loss report page
- **THEN** the page displays the title `Laporan Laba Rugi`
- **AND** the page displays a currency subtitle in the form `(dalam IDR)` using the active setting currency when available
- **AND** the page displays date filters labeled `Tanggal awal` and `Tanggal akhir`
- **AND** the page keeps actions to filter the report and export it to Excel

#### Scenario: Report renders operational table sections

- **WHEN** a permitted user filters the report for a valid date range
- **THEN** the report table displays a period column header for the selected range
- **AND** the report displays a `Pendapatan` section containing `Penjualan` and `Retur Penjualan`
- **AND** the report displays `Total Pendapatan Bersih`
- **AND** the report displays a `Biaya` section containing `Pembelian`, `Retur Pembelian`, and `Beban`
- **AND** the report displays `Total Biaya`
- **AND** the report displays a final emphasized `Laba (Rugi)` row

#### Scenario: Report formats negative amounts with parentheses

- **WHEN** a displayed row represents a subtractive amount or a negative total
- **THEN** the amount is formatted with parentheses, such as `(2.000.000,00)`

#### Scenario: Report removes summary cards

- **WHEN** the profit/loss report page is rendered
- **THEN** the previous summary cards for sales, returns, profit, purchases, expenses, and payments are not displayed
- **AND** the tabular report is the primary report body

### Requirement: Profit/loss export matches screen

The system SHALL export the same operational profit/loss rows and totals shown on the screen.

#### Scenario: Excel export uses operational report rows

- **WHEN** a permitted user exports the profit/loss report after filtering a date range
- **THEN** the Excel file contains the same operational rows and subtotal labels as the screen
- **AND** the exported `Total Pendapatan Bersih`, `Total Biaya`, and `Laba (Rugi)` values match the screen for the same filters

#### Scenario: Export excludes ledger-only rows

- **WHEN** a permitted user exports the profit/loss report
- **THEN** the Excel file does not include chart-of-account codes, account drill-down references, `Beban Pokok Pendapatan`, `Laba Kotor`, or `Laba Operasional`

### Requirement: Existing access and entry points remain stable

The operational profit/loss report SHALL remain available through the existing route and permission model.

#### Scenario: Existing route renders operational report

- **WHEN** a user with `reports.access` visits the existing `profit-loss-report.index` route
- **THEN** the system renders the operational profit/loss report page

#### Scenario: Existing permission denies unauthorized access

- **WHEN** a user without `reports.access` visits the existing `profit-loss-report.index` route
- **THEN** the system denies access
