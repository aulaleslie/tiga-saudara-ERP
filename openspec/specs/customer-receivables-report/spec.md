# customer-receivables-report Specification

## Purpose

Provide a Customer Receivables Report (Laporan Piutang Pelanggan) that lists outstanding sales invoices grouped by customer, with as-of-date balances computed from the payment ledger, supporting filtering, sorting, per-customer subtotals, and export parity.

## Requirements

### Requirement: Report access and navigation
The system SHALL expose a Customer Receivables Report (Laporan Piutang Pelanggan) reachable from the Penjualan tab of the Reports landing, and SHALL restrict access to users holding the `saleReports.access` permission.

#### Scenario: Authorized user opens the report from the landing
- **WHEN** a user with `saleReports.access` views the Reports landing Penjualan tab
- **THEN** a "Piutang pelanggan" card is shown that links to the report route (not a placeholder)
- **AND** following the link renders the Customer Receivables Report page

#### Scenario: Unauthorized user is blocked
- **WHEN** a user without `saleReports.access` requests the report route
- **THEN** the system responds with HTTP 403
- **AND** the "Piutang pelanggan" card is not shown on the landing for that user

### Requirement: Outstanding invoices grouped by customer
The report SHALL list sales invoices that have a remaining receivable balance greater than zero as of the selected as-of date, grouped by customer, scoped to the active tenant (`setting_id`). Each invoice row SHALL show the invoice date, transaction type label, invoice reference number, due date, description, the invoice total amount (Jumlah), and the remaining receivable (Sisa Piutang).

#### Scenario: Only outstanding invoices appear
- **WHEN** the report is generated for an as-of date
- **THEN** only invoices whose remaining receivable as of that date is greater than zero are listed
- **AND** fully settled invoices (remaining receivable of zero as of that date) are excluded

#### Scenario: Invoices are grouped under their customer
- **WHEN** a customer has multiple outstanding invoices
- **THEN** those invoices appear together under a single customer group header
- **AND** invoices belonging to other tenants are not shown

#### Scenario: Customer with no outstanding invoices is omitted
- **WHEN** a customer has no invoices with a remaining receivable as of the as-of date
- **THEN** that customer does not appear in the report

### Requirement: As-of remaining balance from the payment ledger
The remaining receivable for an invoice SHALL be computed as the invoice total minus the sum of active (non-invalidated) payments applied to that invoice with a payment date on or before the as-of date. The report SHALL NOT read the mutable current `sales.due_amount` for this value, so that a back-dated as-of date reflects the balance as it stood on that date.

#### Scenario: Back-dated as-of excludes later payments
- **WHEN** an invoice received a payment after the selected as-of date
- **THEN** that later payment is not subtracted from the remaining receivable
- **AND** the remaining receivable reflects only payments dated on or before the as-of date

#### Scenario: Invalidated payments are not counted
- **WHEN** a payment applied to an invoice has been invalidated
- **THEN** that payment amount is not subtracted from the remaining receivable

#### Scenario: As-of date equals today matches current balance
- **WHEN** the as-of date is today and no payments are back-dated or invalidated relative to it
- **THEN** each invoice's remaining receivable equals its current outstanding balance

### Requirement: Per-customer subtotals
For each customer group the report SHALL display a subtotal row summing the invoice totals (Jumlah) and the remaining receivables (Sisa Piutang) of that customer's listed invoices.

#### Scenario: Subtotal sums the group
- **WHEN** a customer group lists two or more outstanding invoices
- **THEN** a subtotal row shows the sum of their Jumlah values and the sum of their Sisa Piutang values

### Requirement: Filtering
The report SHALL support filtering by as-of date (with period presets), an optional due-date-until cutoff, customer multi-select, and tag grouping with all/any logic. When no customer filter is applied, all customers with outstanding invoices SHALL be considered.

#### Scenario: As-of date controls the cutoff
- **WHEN** the user selects an as-of date
- **THEN** invoices dated after that date are excluded and balances are computed as of that date

#### Scenario: Due-date-until filter
- **WHEN** the user sets a due-date-until value
- **THEN** only invoices with a due date on or before that value are listed

#### Scenario: Customer filter
- **WHEN** the user selects one or more customers
- **THEN** only those customers' outstanding invoices are listed

#### Scenario: Tag grouping with all/any logic
- **WHEN** the user selects tags with "all" logic
- **THEN** only invoices carrying every selected tag are listed
- **WHEN** the user selects tags with "any" logic
- **THEN** invoices carrying at least one selected tag are listed

### Requirement: Sorting
The report SHALL support sorting customer groups by customer name or by total remaining balance, in ascending or descending order, without interleaving rows of different customers.

#### Scenario: Sort by total remaining balance descending
- **WHEN** the user sorts by total remaining balance descending
- **THEN** customer groups are ordered from highest to lowest total Sisa Piutang
- **AND** all invoices of a customer remain contiguous within that customer's group

#### Scenario: Sort by customer name ascending
- **WHEN** the user sorts by customer name ascending
- **THEN** customer groups are ordered alphabetically by customer name

### Requirement: Export parity
The report SHALL support exporting the currently displayed result to PDF, XLSX, and CSV. An export SHALL reflect the same filtered, as-of result shown on screen.

#### Scenario: Export matches the on-screen result
- **WHEN** the user exports after generating the report
- **THEN** the exported file contains the same customers, invoices, and remaining receivable values as the on-screen result for the same filters and as-of date

#### Scenario: Export blocked when filters changed without regenerating
- **WHEN** the user changes filters but has not regenerated the report
- **THEN** the export is not produced from the stale snapshot
