# expense-details-report Specification

## Purpose
Display approved, non-archived expense transactions in the `Detail pengeluaran` (Rincian Biaya) report, grouped by expense category with date-range, category, tag, and sort filters, category subtotals, a grand total, a transaction count, and CSV/XLSX/PDF exports.

## Requirements

### Requirement: Expense details report access
The system SHALL provide a `Detail pengeluaran` report page for authorized purchase-report users.

#### Scenario: Authorized user opens report
- **WHEN** a user with `purchaseReports.access` requests the Detail pengeluaran report route
- **THEN** the system MUST render the report page
- **AND** the page MUST show the title `Rincian Biaya`
- **AND** the page MUST indicate the report currency is IDR

#### Scenario: Authorized user opens report from landing page
- **WHEN** a user with `purchaseReports.access` views the Pembelian tab on the Reports landing page
- **THEN** the `Detail pengeluaran` card MUST be shown as an actionable report link
- **AND** the card MUST NOT show placeholder or unavailable-state treatment

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `purchaseReports.access` requests the Detail pengeluaran report route
- **THEN** the system MUST deny access

### Requirement: Expense details report filters
The system SHALL support filtering Detail pengeluaran by date range, expense category, tags, and applied sort direction.

#### Scenario: Default date range uses current month
- **WHEN** a user opens the Detail pengeluaran report
- **THEN** the report filter state MUST default `Tanggal Mulai` to the first day of the current month
- **AND** the report filter state MUST default `Tanggal Selesai` to the last day of the current month

#### Scenario: Date range filters expenses
- **WHEN** a user applies `Tanggal Mulai` and `Tanggal Selesai`
- **THEN** the report MUST include only expenses whose expense date is within the inclusive date range

#### Scenario: Category filter limits groups and rows
- **WHEN** a user applies one or more expense categories
- **THEN** the report MUST include only expenses assigned to one of the selected categories
- **AND** groups for unselected categories MUST NOT be rendered

#### Scenario: Tag filter with Mencakup Semua
- **WHEN** a user selects multiple tags and chooses `Mencakup Semua`
- **THEN** the report MUST include only expenses that have every selected tag

#### Scenario: Tag filter with Salah Satu
- **WHEN** a user selects multiple tags and chooses `Salah Satu`
- **THEN** the report MUST include expenses that have at least one selected tag

#### Scenario: Sort direction is applied
- **WHEN** a user applies ascending or descending sort direction
- **THEN** the report MUST order expense rows within each category by expense date using the selected direction
- **AND** the report MUST apply a deterministic secondary order by expense identifier

#### Scenario: Invalid date range is rejected
- **WHEN** a user applies an end date before the start date
- **THEN** the system MUST reject the filter
- **AND** the report MUST NOT export using the invalid range

### Requirement: Expense details report row inclusion
The system SHALL include only approved, non-archived expenses from the current setting in Detail pengeluaran.

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

### Requirement: Expense details report grouped rows
The system SHALL render Detail pengeluaran as category-grouped transaction rows matching the sample Rincian Biaya columns.

#### Scenario: Category header uses expense category name
- **WHEN** matching expenses exist for an expense category
- **THEN** the report MUST render a group header using `expense_categories.category_name`

#### Scenario: Transaction row maps expense header
- **WHEN** a matching expense is rendered
- **THEN** the report MUST render one row with columns `Kategori / Tanggal`, `Transaksi`, `Nomor`, `Keterangan`, and `Jumlah`
- **AND** `Kategori / Tanggal` MUST display the expense date in `dd/mm/yyyy` format
- **AND** `Transaksi` MUST be `Expense`
- **AND** `Nomor` MUST use the expense reference
- **AND** `Keterangan` MUST use the expense details summary
- **AND** `Jumlah` MUST equal the approved expense total

#### Scenario: Expense with multiple structured details remains one transaction row
- **WHEN** a matching expense has multiple `expense_details` rows
- **THEN** Detail pengeluaran MUST render one transaction row for the expense
- **AND** `Keterangan` MUST use the expense header details summary rather than creating one row per detail item

#### Scenario: Empty description renders blank value
- **WHEN** a matching expense has no details summary
- **THEN** the `Keterangan` column MUST render a blank value

### Requirement: Expense details report totals
The system SHALL display category subtotals, grand total, and transaction count for matching Detail pengeluaran rows.

#### Scenario: Category subtotal is rendered
- **WHEN** a category group contains matching expenses
- **THEN** the report MUST render a subtotal row labeled `Total <category name>`
- **AND** the subtotal amount MUST equal the sum of matching expense totals in that category group

#### Scenario: Grand total is rendered
- **WHEN** the report contains one or more matching expenses
- **THEN** the UI MUST render a final row labeled `Grand Total`
- **AND** the grand total amount MUST equal the sum of all matching expense totals

#### Scenario: Transaction count is rendered
- **WHEN** the report contains matching expenses
- **THEN** the UI MUST display `Menampilkan total dari <n> baris transaksi`
- **AND** `<n>` MUST equal the count of matching expenses, excluding group headers, subtotals, and grand total

#### Scenario: Empty result shows no totals
- **WHEN** no expenses match the applied filters
- **THEN** the report MUST show an empty-state message
- **AND** the report MUST NOT render category subtotals or grand total rows

### Requirement: Expense details report exports
The system SHALL export Detail pengeluaran to CSV, XLSX, and PDF using the active applied filters and the sample-specific format for each export type.

#### Scenario: Export requires applied filters
- **WHEN** a user attempts to export before applying the current filter state
- **THEN** the system MUST block the export
- **AND** the system MUST tell the user to apply filters first

#### Scenario: Export blocks stale filter state
- **WHEN** a user applies filters, changes a filter value, and exports without re-applying filters
- **THEN** the system MUST block the export
- **AND** the system MUST tell the user to apply filters first

#### Scenario: CSV export uses flat transaction rows
- **WHEN** a user exports CSV
- **THEN** the downloaded file MUST contain the columns `Kategori / Tanggal`, `Transaksi`, `Nomor`, `Keterangan`, and `Jumlah`
- **AND** subsequent CSV rows MUST contain one row per matching expense
- **AND** the CSV MUST NOT include company name, report title, date range rows, category headers, subtotal rows, grand total rows, or transaction-count rows
- **AND** numeric values MUST be exported as raw numeric values rather than localized display strings
- **AND** the CSV MUST be standards-compliant comma-separated content

#### Scenario: XLSX export includes grouped report structure
- **WHEN** a user exports XLSX
- **THEN** the workbook MUST include the company name, report title `Rincian Biaya`, selected date range, currency note `(dalam IDR)`, table headers, category group headers, expense rows, category subtotal rows, and a final total row
- **AND** the final total row label MUST be `Grand Total Biaya`

#### Scenario: PDF export includes grouped report structure
- **WHEN** a user exports PDF
- **THEN** the PDF MUST include the report title `Rincian Biaya`, selected date range, currency note `(dalam IDR)`, table headers, category group headers, expense rows, category subtotal rows, and a final total row
- **AND** the final total row label MUST be `Grand Total Biaya`

#### Scenario: Export filenames identify report and date range
- **WHEN** a user exports the report for period 01/01/2026 to 31/12/2026
- **THEN** the downloaded XLSX filename MUST identify `expense_details` and the selected date range
- **AND** the downloaded CSV filename MUST identify `expense_details` and the selected date range
- **AND** the downloaded PDF filename MUST identify `expense_details` and the selected date range
