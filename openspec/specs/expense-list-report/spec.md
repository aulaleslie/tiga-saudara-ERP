# expense-list-report Specification

## Purpose
TBD - created by archiving change add-expense-list-report. Update Purpose after archive.
## Requirements
### Requirement: Expense list report access
The system SHALL provide a Daftar Pengeluaran report page for authorized purchase-report users.

#### Scenario: Authorized user opens report
- **WHEN** a user with `purchaseReports.access` requests the Daftar Pengeluaran report route
- **THEN** the system MUST render the report page
- **AND** the page MUST show the title `Daftar Pengeluaran`
- **AND** the page MUST indicate the report currency is IDR

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `purchaseReports.access` requests the Daftar Pengeluaran report route
- **THEN** the system MUST deny access

### Requirement: Expense list report filters
The system SHALL support filtering Daftar Pengeluaran by date range, supplier, tags, and applied sort options.

#### Scenario: Default date range uses current month
- **WHEN** a user opens the Daftar Pengeluaran report
- **THEN** the report filter state MUST default `Tanggal Mulai` to the first day of the current month
- **AND** the report filter state MUST default `Tanggal Selesai` to the last day of the current month

#### Scenario: Date range filters expenses
- **WHEN** a user applies `Tanggal Mulai` and `Tanggal Selesai`
- **THEN** the report MUST include only expenses whose expense date is within the inclusive date range

#### Scenario: Supplier filter limits rows
- **WHEN** a user applies one or more suppliers
- **THEN** the report MUST include only expenses assigned to one of the selected suppliers
- **AND** expenses with no supplier MUST be excluded while the supplier filter is active

#### Scenario: Tag filter with Mencakup Semua
- **WHEN** a user selects multiple tags and chooses `Mencakup Semua`
- **THEN** the report MUST include only expenses that have every selected tag

#### Scenario: Tag filter with Salah Satu
- **WHEN** a user selects multiple tags and chooses `Salah Satu`
- **THEN** the report MUST include expenses that have at least one selected tag

#### Scenario: Sort options are applied
- **WHEN** a user applies a sort column and sort direction
- **THEN** the report MUST order rows by the selected column and direction
- **AND** the report MUST apply a deterministic secondary order by expense identifier

### Requirement: Expense list report row inclusion
The system SHALL include only approved, non-archived expenses from the current setting in Daftar Pengeluaran.

#### Scenario: Approved active expense appears
- **WHEN** an expense belongs to the current setting, has status `APPROVED`, is not archived, and matches the active filters
- **THEN** the report MUST include the expense

#### Scenario: Non-approved expenses are excluded
- **WHEN** an expense has status `DRAFT`, `SUBMITTED`, or `REJECTED`
- **THEN** the report MUST NOT include the expense

#### Scenario: Archived approved expense is excluded
- **WHEN** an approved expense has `archived_at` set
- **THEN** the report MUST NOT include the expense

#### Scenario: Other setting expense is excluded
- **WHEN** an expense belongs to a different setting than the current session setting
- **THEN** the report MUST NOT include the expense

### Requirement: Expense list report summary rows
The system SHALL render summary-mode rows matching the sample Daftar Pengeluaran columns.

#### Scenario: Summary row maps expense header
- **WHEN** the report is shown without `Perlihatkan Lebih Detail`
- **THEN** each matching expense MUST render one row with columns `Tanggal`, `Transaksi`, `Nomor`, `Kategori`, `Deskripsi`, `Supplier`, `Jumlah`, `Tax`, `Status`, and `Sisa Tagihan`
- **AND** `Transaksi` MUST be `Expense`
- **AND** `Nomor` MUST use the expense reference
- **AND** `Kategori` MUST use the expense category name
- **AND** `Deskripsi` MUST use the expense details summary

#### Scenario: Null supplier displays placeholder
- **WHEN** a matching expense has no supplier
- **THEN** the row's `Supplier` value MUST be `-`

#### Scenario: Summary monetary columns are calculated
- **WHEN** a matching expense is rendered in summary mode
- **THEN** `Jumlah` MUST equal the approved expense total
- **AND** `Tax` MUST equal the total tax amount represented by the expense detail rows and tax-included setting
- **AND** `Status` MUST be `Paid`
- **AND** `Sisa Tagihan` MUST be `0`

### Requirement: Expense list report detail rows
The system SHALL support a detail mode that expands expenses into their structured expense detail rows.

#### Scenario: Detail mode expands expense details
- **WHEN** a user enables `Perlihatkan Lebih Detail`
- **THEN** each matching expense detail row MUST render as a report row
- **AND** the row MUST repeat the parent expense date, transaction type, reference, category, supplier, status, and outstanding values
- **AND** the row's `Deskripsi` MUST use the expense detail name

#### Scenario: Detail mode monetary columns are detail-level
- **WHEN** a detail-mode row is rendered
- **THEN** `Jumlah` MUST equal the expense detail amount before any separate header aggregation
- **AND** `Tax` MUST equal the tax amount for that expense detail row using the same tax-included rules as expense persistence

#### Scenario: Expense with no structured details remains visible
- **WHEN** a matching approved expense has no structured detail rows
- **THEN** detail mode MUST still render a compatible row using the expense header summary and total

### Requirement: Expense list report totals
The system SHALL display and export grand totals for matching rows without double-counting detail mode.

#### Scenario: Summary totals use matching expenses
- **WHEN** the report is shown in summary mode
- **THEN** the total `Jumlah` MUST equal the sum of matching approved expense totals
- **AND** the total `Sisa Tagihan` MUST equal `0`

#### Scenario: Detail totals reconcile to matching expenses
- **WHEN** the report is shown in detail mode
- **THEN** the total `Jumlah` MUST reconcile to the sum of matching approved expense totals
- **AND** the report MUST NOT double-count an expense because it has multiple detail rows

### Requirement: Expense list report exports
The system SHALL export Daftar Pengeluaran to CSV, XLSX, and PDF using the active applied filters and the same row mapping as the visible report.

#### Scenario: Export requires applied filters
- **WHEN** a user attempts to export before applying the current filter state
- **THEN** the system MUST block the export
- **AND** the system MUST tell the user to apply filters first

#### Scenario: CSV export uses clean CSV
- **WHEN** a user exports CSV
- **THEN** the downloaded file MUST contain the columns `Tanggal`, `Transaksi`, `Nomor`, `Kategori`, `Deskripsi`, `Supplier`, `Jumlah`, `Tax`, `Status`, and `Sisa Tagihan`
- **AND** the CSV MUST be standards-compliant comma-separated content
- **AND** numeric values MUST be exported as raw numeric values rather than localized display strings
- **AND** the CSV MUST NOT intentionally include tab characters to mimic the sample artifact

#### Scenario: XLSX export includes report heading
- **WHEN** a user exports XLSX
- **THEN** the workbook MUST include the company name, report title `Daftar Pengeluaran`, selected date range, table headers, data rows, and a total row labeled `Total Biaya`

#### Scenario: PDF export matches report rows
- **WHEN** a user exports PDF
- **THEN** the PDF export MUST use the same applied filters, row columns, row values, and totals as the report table

