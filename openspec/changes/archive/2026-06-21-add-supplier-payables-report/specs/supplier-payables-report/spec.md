## ADDED Requirements

### Requirement: Report access and navigation
The system SHALL expose a Supplier Payables Report (`Utang supplier` / `Laporan Hutang Supplier`) reachable from the Pembelian tab of the Reports landing, and SHALL restrict access to users holding the `purchaseReports.access` permission.

#### Scenario: Authorized user opens the report from the landing
- **WHEN** a user with `purchaseReports.access` views the Reports landing Pembelian tab
- **THEN** an `Utang supplier` card is shown that links to the supplier payables report route
- **AND** following the link renders the Supplier Payables Report page

#### Scenario: Unauthorized user is blocked
- **WHEN** a user without `purchaseReports.access` requests the supplier payables report route
- **THEN** the system responds with HTTP 403
- **AND** the `Utang supplier` card is not shown on the landing for that user

### Requirement: Outstanding purchase invoices grouped by supplier
The report SHALL list purchase invoices that have a remaining payable balance greater than zero as of the selected as-of date, grouped by supplier, scoped to the active tenant (`setting_id`). Each invoice row SHALL show the invoice date, transaction type label, invoice reference number, due date, description, invoice total amount (`Jumlah`), and remaining payable balance (`Saldo`).

#### Scenario: Only outstanding purchase invoices appear
- **WHEN** the report is generated for an as-of date
- **THEN** only purchase invoices whose remaining payable balance as of that date is greater than zero are listed
- **AND** fully settled purchase invoices are excluded

#### Scenario: Purchase invoices are grouped under their supplier
- **WHEN** a supplier has multiple outstanding purchase invoices
- **THEN** those invoices appear together under a single supplier group header
- **AND** purchase invoices belonging to other tenants are not shown

#### Scenario: Supplier with no outstanding purchase invoices is omitted
- **WHEN** a supplier has no purchase invoices with a remaining payable balance as of the as-of date
- **THEN** that supplier does not appear in the report

### Requirement: As-of remaining payable balance from the purchase payment ledger
The remaining payable balance for a purchase invoice SHALL be computed as the purchase invoice total minus the sum of active purchase payments applied to that invoice with a payment date on or before the as-of date. The report SHALL NOT read the mutable current `purchases.due_amount` for this value, so that a back-dated as-of date reflects the balance as it stood on that date.

#### Scenario: Back-dated as-of excludes later purchase payments
- **WHEN** a purchase invoice received a payment after the selected as-of date
- **THEN** that later payment is not subtracted from the remaining payable balance
- **AND** the remaining payable balance reflects only active payments dated on or before the as-of date

#### Scenario: Invalidated purchase payments are not counted
- **WHEN** a payment applied to a purchase invoice has been invalidated
- **THEN** that payment amount is not subtracted from the remaining payable balance

#### Scenario: As-of date equals today matches current unpaid balance
- **WHEN** the as-of date is today and no payments are back-dated or invalidated relative to it
- **THEN** each purchase invoice's remaining payable balance equals its current outstanding balance

### Requirement: Supplier subtotals and grand totals
For each supplier group the report SHALL display a subtotal row summing the invoice totals (`Jumlah`) and remaining payable balances (`Saldo`) of that supplier's listed purchase invoices. The report SHALL also display a grand total row summing `Jumlah` and `Saldo` across all listed purchase invoices.

#### Scenario: Supplier subtotal sums the group
- **WHEN** a supplier group lists two or more outstanding purchase invoices
- **THEN** the supplier subtotal row shows the sum of their `Jumlah` values
- **AND** the supplier subtotal row shows the sum of their `Saldo` values

#### Scenario: Grand total sums every listed invoice
- **WHEN** the report lists purchase invoices across multiple suppliers
- **THEN** the grand total row shows the sum of all listed `Jumlah` values
- **AND** the grand total row shows the sum of all listed `Saldo` values

### Requirement: Filtering
The report SHALL support filtering by as-of date with period presets, an optional due-date-until cutoff, supplier multi-select, and tag grouping with all/any logic. When no supplier filter is applied, all suppliers with outstanding purchase invoices SHALL be considered.

#### Scenario: As-of date controls invoice cutoff
- **WHEN** the user selects an as-of date
- **THEN** purchase invoices dated after that date are excluded
- **AND** remaining payable balances are computed as of that date

#### Scenario: Due-date-until filter
- **WHEN** the user sets a due-date-until value
- **THEN** only purchase invoices with a due date on or before that value are listed

#### Scenario: Supplier filter
- **WHEN** the user selects one or more suppliers
- **THEN** only those suppliers' outstanding purchase invoices are listed

#### Scenario: Tag grouping with all logic
- **WHEN** the user selects tags with `Mencakup semua` logic
- **THEN** only purchase invoices carrying every selected tag are listed

#### Scenario: Tag grouping with any logic
- **WHEN** the user selects tags with `Salah satu` logic
- **THEN** purchase invoices carrying at least one selected tag are listed

### Requirement: Sorting
The report SHALL support sorting supplier groups by supplier name or by total remaining payable balance, in ascending or descending order, without interleaving rows of different suppliers.

#### Scenario: Sort by total remaining balance descending
- **WHEN** the user sorts by total remaining balance descending
- **THEN** supplier groups are ordered from highest to lowest total `Saldo`
- **AND** all purchase invoices of a supplier remain contiguous within that supplier's group

#### Scenario: Sort by supplier name ascending
- **WHEN** the user sorts by supplier name ascending
- **THEN** supplier groups are ordered alphabetically by supplier name

### Requirement: Export parity
The report SHALL support exporting the currently displayed filtered result to PDF, XLSX, and CSV. Each export SHALL reflect the same filtered, as-of result shown on screen and SHALL include the sample-aligned columns `Supplier`, `Date`, `Transaksi`, `No.`, `Jatuh Tempo`, `Keterangan`, `Jumlah`, and `Saldo` for invoice rows.

#### Scenario: Export matches the applied report result
- **WHEN** the user exports after generating the report
- **THEN** the exported file contains the same suppliers, purchase invoices, invoice totals, and remaining payable balances as the on-screen result for the same filters and as-of date

#### Scenario: Export includes group and grand totals
- **WHEN** the user exports a report with one or more supplier groups
- **THEN** the exported file includes supplier subtotal rows
- **AND** the exported file includes a grand total row

#### Scenario: Export blocked when filters changed without regenerating
- **WHEN** the user changes filters but has not regenerated the report
- **THEN** the export is not produced from the stale snapshot

### Requirement: V1 excludes supplier credits and debit memo rows
The report SHALL list outstanding purchase invoices only. Supplier credits, purchase return credits, debit memos, and unapplied supplier balances SHALL NOT appear as separate report rows unless they have already been represented as active purchase payment rows that reduce a listed purchase invoice's remaining payable balance.

#### Scenario: Supplier credit record is not emitted as a separate row
- **WHEN** a supplier has an unapplied credit record and no outstanding purchase invoice row representing that credit
- **THEN** the supplier credit is not displayed as its own row in the Supplier Payables Report
- **AND** the credit does not change a purchase invoice balance unless represented through an active purchase payment row for that invoice
