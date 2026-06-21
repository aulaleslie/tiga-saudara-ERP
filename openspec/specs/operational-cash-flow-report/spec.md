## ADDED Requirements

### Requirement: Reports expose operational Arus Kas
The system SHALL provide an `Arus Kas` report page for users with `reports.access`.

#### Scenario: Authorized user opens Arus Kas
- **WHEN** a user with `reports.access` requests the Arus Kas report route
- **THEN** the system renders the Arus Kas report page
- **AND** the page title is `Arus Kas`
- **AND** the report displays the active setting currency.

#### Scenario: Unauthorized user cannot open Arus Kas
- **WHEN** a user without `reports.access` requests the Arus Kas report route
- **THEN** the system returns a forbidden response.

### Requirement: Arus Kas uses operational cash movement sources
The system SHALL calculate Arus Kas from supported operational cash movement records instead of complete accounting journal, chart-of-account, bank ledger, or opening capital balances.

#### Scenario: Report explains operational source
- **WHEN** Arus Kas is rendered or exported
- **THEN** the output includes a note explaining that the report is calculated from supported operational cash movements
- **AND** the note states that it does not yet use complete accounting journals, chart-of-account posting, bank ledger balances, opening capital, or bank revaluation data.

#### Scenario: Report is scoped to active setting
- **WHEN** operational cash movement records exist for multiple settings
- **THEN** Arus Kas includes only records for the active `setting_id`.

### Requirement: Arus Kas supports date range filtering
The system SHALL calculate Arus Kas for a selected date range.

#### Scenario: Default period is current date
- **WHEN** the user opens Arus Kas without applying a filter
- **THEN** the report uses the current date as both the start date and end date.

#### Scenario: User applies date range
- **WHEN** the user selects a start date and end date and applies the filter
- **THEN** the report includes supported cash movement dated within the selected range
- **AND** opening cash is calculated from supported cash movement before the start date.

#### Scenario: Invalid date range is rejected
- **WHEN** the user selects an end date before the start date
- **THEN** the system rejects the filter
- **AND** the report does not export using the invalid range.

#### Scenario: Period preset updates dates
- **WHEN** the user selects a supported period preset such as today, this week, this month, or this year
- **THEN** the start date and end date are updated to the matching period boundaries.

### Requirement: Arus Kas presents direct-method sections
The system SHALL render direct-method cash-flow sections for operating, investing, and financing activity.

#### Scenario: Operating section is shown
- **WHEN** Arus Kas is rendered
- **THEN** the report shows `Arus kas dari aktivitas operasional`
- **AND** it includes rows for `Penerimaan dari pelanggan`, `Pembayaran ke pemasok`, `Aset lancar lainnya`, `Kartu kredit dan liabilitas jangka pendek lainnya`, `Pendapatan lainnya`, and `Pengeluaran operasional`
- **AND** it shows a subtotal row for `Kas bersih yang diperoleh dari aktivitas operasional`.

#### Scenario: Investing section is shown
- **WHEN** Arus Kas is rendered
- **THEN** the report shows `Arus kas dari aktivitas investasi`
- **AND** it includes rows for `Perolehan/Penjualan aset` and `Aktivitas investasi lainnya`
- **AND** it shows a subtotal row for `Kas bersih yang diperoleh dari aktivitas investasi`.

#### Scenario: Financing section is shown
- **WHEN** Arus Kas is rendered
- **THEN** the report shows `Arus kas dari aktivitas pendanaan`
- **AND** it includes rows for `Pembayaran/Penerimaan pinjaman` and `Ekuitas/Modal`
- **AND** it shows a subtotal row for `Kas bersih yang diperoleh dari aktivitas pendanaan`.

### Requirement: Arus Kas classifies operating cash movement
The system SHALL classify supported payment and expense records into operating cash-flow rows.

#### Scenario: Sale payments increase customer receipts
- **WHEN** active sale payments exist within the selected period for eligible sales in the active setting
- **THEN** their amounts increase `Penerimaan dari pelanggan`.

#### Scenario: Purchase payments increase supplier payments
- **WHEN** active purchase payments exist within the selected period for eligible purchases in the active setting
- **THEN** their amounts decrease `Pembayaran ke pemasok`.

#### Scenario: Sale return refunds reduce operating cash
- **WHEN** sale return payment records exist within the selected period for completed sale returns in the active setting
- **THEN** their amounts reduce operating cash in an appropriate operating row.

#### Scenario: Purchase return refunds increase operating cash
- **WHEN** purchase return payment records exist within the selected period for completed purchase returns in the active setting
- **THEN** their amounts increase operating cash in an appropriate operating row.

#### Scenario: Approved expenses reduce operating cash
- **WHEN** approved, non-archived expenses exist within the selected period in the active setting
- **THEN** their amounts decrease `Pengeluaran operasional`.

#### Scenario: Ineligible records are excluded
- **WHEN** draft, rejected, archived, inactive payment, or incomplete lifecycle records exist
- **THEN** their amounts do not contribute to Arus Kas.

### Requirement: Arus Kas calculates summary cash rows
The system SHALL calculate net cash movement, bank revaluation, opening cash, and ending cash from supported cash-flow rows.

#### Scenario: Opening cash uses prior supported movement
- **WHEN** supported cash movement exists before the selected start date
- **THEN** the report sums that movement as `Saldo kas awal`.

#### Scenario: Net cash movement is section total
- **WHEN** the report has operating, investing, and financing section subtotals
- **THEN** `Kenaikan (penurunan) kas` equals the sum of those section subtotals.

#### Scenario: Bank revaluation is explicit placeholder
- **WHEN** the first operational version renders Arus Kas
- **THEN** `Total revaluasi bank` is shown as zero
- **AND** the report note explains that bank revaluation is not yet sourced from a bank ledger.

#### Scenario: Ending cash reconciles with supported movement
- **WHEN** Arus Kas is rendered
- **THEN** `Saldo kas akhir` equals `Saldo kas awal` plus `Kenaikan (penurunan) kas` plus `Total revaluasi bank`.

### Requirement: Arus Kas formats amounts consistently
The system SHALL display and export cash-flow amounts using existing currency formatting conventions.

#### Scenario: Positive and negative values are distinguishable
- **WHEN** a cash-flow row amount is positive or negative
- **THEN** the screen and XLSX export format the amount consistently with existing financial report conventions
- **AND** CSV export emits numeric values suitable for spreadsheet import.

#### Scenario: Zero rows remain visible
- **WHEN** a cash-flow row has no supported movement
- **THEN** the row remains visible with a zero amount so the report shape matches the direct-method sample.

### Requirement: Arus Kas exports XLSX
The system SHALL allow authorized users to export Arus Kas to XLSX using the same filters and calculation output as the screen.

#### Scenario: XLSX export uses current filters
- **WHEN** the user exports Arus Kas to XLSX after applying a date range
- **THEN** the downloaded file uses the same date range, active setting, rows, subtotals, and summary values as the on-screen report.

#### Scenario: XLSX export includes report header
- **WHEN** the XLSX file is generated
- **THEN** it includes the company name, `Arus Kas` title, period label, currency label, and direct-method rows.

### Requirement: Arus Kas exports CSV
The system SHALL allow authorized users to export Arus Kas to CSV using the same filters and calculation output as the screen.

#### Scenario: CSV export uses sample-compatible columns
- **WHEN** the user exports Arus Kas to CSV
- **THEN** the CSV includes columns for activity type, row label, and the selected period label
- **AND** the data rows match the on-screen report values.

#### Scenario: CSV export includes summary rows
- **WHEN** the CSV file is generated
- **THEN** it includes `Kenaikan (penurunan) kas`, `Total revaluasi bank`, `Saldo kas awal`, and `Saldo kas akhir` rows.

### Requirement: Arus Kas handles empty movement
The system SHALL render a complete zero-valued cash-flow structure when no supported movement exists for the selected filters.

#### Scenario: No supported movement exists
- **WHEN** the selected date range and active setting have no supported cash movement before or during the period
- **THEN** Arus Kas displays all direct-method rows with zero amounts
- **AND** `Saldo kas awal`, `Kenaikan (penurunan) kas`, and `Saldo kas akhir` are zero.
