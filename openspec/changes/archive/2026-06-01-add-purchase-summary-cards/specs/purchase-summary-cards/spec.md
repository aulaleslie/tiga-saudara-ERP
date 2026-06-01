## ADDED Requirements

### Requirement: Display Faktur Belum Dibayar card
The system SHALL display a summary card showing the count and total outstanding balance (`due_amount`) of UNPAID purchase invoices with a real outstanding balance, for invoices in status `APPROVED`, `RECEIVED PARTIALLY`, or `RECEIVED`.

An invoice qualifies if `payment_status = UNPAID` AND `due_amount > 0`.

#### Scenario: Card shows correct count and total
- **WHEN** the purchase index page loads
- **THEN** the "Faktur belum dibayar" card displays the count of qualifying invoices and the sum of their `due_amount` values formatted as Rupiah

#### Scenario: Invoices with due_amount = 0 are excluded
- **WHEN** an invoice has `payment_status = UNPAID` but `due_amount = 0`
- **THEN** it is NOT counted in the "Faktur belum dibayar" card

#### Scenario: Only post-approval statuses are included
- **WHEN** an invoice has status `DRAFTED`, `WAITING_APPROVAL`, or `REJECTED`
- **THEN** it is NOT counted in any summary card

### Requirement: Display Faktur Telat Bayar card
The system SHALL display a summary card showing the count and total outstanding balance of UNPAID purchase invoices that are past their due date. This is a subset of "Faktur belum dibayar".

An invoice qualifies if `payment_status = UNPAID` AND `due_amount > 0` AND `due_date < today` AND status in `[APPROVED, RECEIVED PARTIALLY, RECEIVED]`.

#### Scenario: Card shows overdue invoices only
- **WHEN** the purchase index page loads
- **THEN** the "Faktur telat bayar" card displays only invoices whose `due_date` is before today

#### Scenario: Invoice due today is not overdue
- **WHEN** an invoice has `due_date = today`
- **THEN** it is NOT counted in the "Faktur telat bayar" card

#### Scenario: Telat bayar is always a subset of belum dibayar
- **WHEN** viewing both cards simultaneously
- **THEN** the telat bayar count SHALL be less than or equal to the belum dibayar count

### Requirement: Display Pelunasan 30 Hari Terakhir card
The system SHALL display a summary card showing the count and total paid amount of purchase invoices settled (fully paid) in the last 30 days, for invoices in status `APPROVED`, `RECEIVED PARTIALLY`, or `RECEIVED`.

Date source priority:
1. If `purchase_payments` table contains ACTIVE rows with `date >= 30 days ago`, use `purchase_payments.date` — count distinct purchases, sum `purchase_payments.amount`
2. Otherwise, fall back to `purchases.date >= 30 days ago` with `payment_status = PAID`, summing `paid_amount`

#### Scenario: Card uses purchase_payments when available
- **WHEN** `purchase_payments` has ACTIVE rows with dates in the last 30 days
- **THEN** the card counts and sums from `purchase_payments` using `date`

#### Scenario: Card falls back to purchases.date
- **WHEN** `purchase_payments` has no ACTIVE rows in the last 30 days
- **THEN** the card counts PAID purchases with `date >= 30 days ago` and sums `paid_amount`

#### Scenario: Card shows correct 30-day window
- **WHEN** the purchase index page loads
- **THEN** only invoices/payments from the last 30 calendar days (inclusive) are counted

### Requirement: Card click pre-filters the purchase DataTable
The system SHALL allow users to click a summary card to pre-filter the DataTable on the same page to the corresponding invoice subset.

#### Scenario: Clicking Belum Dibayar filters DataTable
- **WHEN** a user clicks the "Faktur belum dibayar" card
- **THEN** the DataTable filters to show only UNPAID invoices with `due_amount > 0`

#### Scenario: Clicking Telat Bayar filters DataTable
- **WHEN** a user clicks the "Faktur telat bayar" card
- **THEN** the DataTable filters to show only UNPAID invoices with `due_date < today`

#### Scenario: Clicking Pelunasan filters DataTable
- **WHEN** a user clicks the "Pelunasan 30 hari terakhir" card
- **THEN** the DataTable filters to show only PAID invoices

#### Scenario: Counts are computed at page load only
- **WHEN** the DataTable data changes due to a filter or sort
- **THEN** the summary card counts do NOT automatically refresh (they reflect state at page load)
