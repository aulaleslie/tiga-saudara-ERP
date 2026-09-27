## ADDED Requirements

### Requirement: Sales list shows AR summary cards

The sales list page (`sales.index`) SHALL display three accounts-receivable summary cards above the sales table, scoped to the current `setting_id`.

#### Scenario: Cards render on the sales list

- **WHEN** a user with `sales.access` opens the sales list page
- **THEN** three cards are shown — open receivables, overdue receivables, and recent collections — before the sales table

#### Scenario: Metrics are scoped to the current setting

- **WHEN** sales exist under multiple settings
- **THEN** each card counts and sums only sales whose `setting_id` matches the active session setting

### Requirement: Open receivables card

The "Piutang Belum Tertagih" card SHALL show the count and summed `due_amount` of sales that have an outstanding balance.

#### Scenario: Only outstanding dispatched sales are counted

- **WHEN** the card is computed
- **THEN** it includes sales where `due_amount > 0`, `payment_status` is UNPAID or PARTIAL, and `status` is one of APPROVED, DISPATCHED PARTIALLY, or DISPATCHED
- **AND** the total is the sum of `due_amount` over those sales

#### Scenario: Fully paid or non-dispatched sales are excluded

- **WHEN** a sale is PAID, or is in a draft/waiting/rejected status
- **THEN** it is not counted in the open receivables card

### Requirement: Overdue receivables card

The "Piutang Telat" card SHALL show the subset of open receivables whose `due_date` has passed.

#### Scenario: Only past-due receivables are counted

- **WHEN** the card is computed
- **THEN** it includes the open-receivables set additionally filtered to `due_date < today`
- **AND** the total is the sum of `due_amount` over that subset

### Requirement: Recent collections card

The "Penerimaan (30 hari)" card SHALL show collections received within the last 30 days.

#### Scenario: Collections come from active sale payments

- **WHEN** active `SalePayment` rows exist in the last 30 days for sales in the current setting
- **THEN** the card shows the count of distinct invoices and the summed payment amount

#### Scenario: Collection amounts are not divided by 100

- **WHEN** the collections total is computed from `SalePayment.amount`
- **THEN** the amount is used directly in rupiah and is NOT divided by 100 (because `SalePayment.amount` is cast `decimal:2`, unlike `PurchasePayment` which stores cents)

#### Scenario: Fallback to paid amount when no active payments

- **WHEN** no active `SalePayment` rows exist in the window but fully-paid sales do
- **THEN** the card falls back to counting PAID sales dated in the last 30 days and summing their `paid_amount`
