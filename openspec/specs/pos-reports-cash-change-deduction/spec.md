# pos-reports-cash-change-deduction Specification

## Purpose
TBD - created by archiving change fix-pos-reports-cash-change-calculation. Update Purpose after archive.
## Requirements
### Requirement: Cash report totals MUST deduct change from payment amounts
When aggregating cash sales totals in reports, the system SHALL subtract the change amount given to customers from the raw cash payment amounts, ensuring reports show actual cash received rather than cash tendered.

#### Scenario: Single payment with overpayment
- **WHEN** a customer pays 100,000 cash for a 75,000 transaction
- **THEN** the cash total in daily sales report shows 75,000 (actual received after 25,000 change)

#### Scenario: Multi-payment with overpayment
- **WHEN** a customer pays 50,000 cash + 30,000 QRIS for a 75,000 transaction
- **THEN** the cash total in daily sales report shows 45,000 (50,000 cash tendered minus 5,000 change)

#### Scenario: No overpayment
- **WHEN** a customer pays exactly the transaction amount
- **THEN** the cash total equals the payment amount (no change to deduct)

#### Scenario: Non-cash payment only
- **WHEN** a transaction is paid entirely with non-cash methods
- **THEN** cash total shows 0 (no change deduction applicable)

### Requirement: Daily sales MUST report accurate cash totals per day
The daily sales summary report SHALL aggregate cash payment amounts minus change per transaction, grouped by date, ensuring daily cash totals match actual cash received.

#### Scenario: Daily sales reflects change adjustment
- **WHEN** user views daily sales report for a date with mixed transactions (some with change, some without)
- **THEN** cash_total field shows the sum of all cash payments minus all change amounts for that day

#### Scenario: Daily sales includes multiple payment methods
- **WHEN** a day has transactions with mixed payment methods (cash + QRIS, cash-only, QRIS-only, etc.)
- **THEN** cash_total includes only cash payments with change deduction, non_cash_total includes all non-cash

#### Scenario: Daily sales with zero transactions
- **WHEN** date range has no transactions
- **THEN** cash_total shows 0

### Requirement: Cashier summary MUST report accurate cash totals per cashier
The cashier performance summary report SHALL aggregate cash payment amounts minus change per transaction, grouped by cashier, ensuring individual cashier totals match actual cash handled by each person.

#### Scenario: Cashier summary reflects change adjustment
- **WHEN** user views cashier summary
- **THEN** each cashier's cash_total shows their cash payments minus change given by them

#### Scenario: Average basket reflects correct grand totals
- **WHEN** cashier summary calculates average basket (grand_total / transaction_count)
- **THEN** grand_total uses unadjusted checkout totals, but cash_total shows adjusted amounts

#### Scenario: Cashier with no cash transactions
- **WHEN** a cashier handled no cash transactions
- **THEN** their cash_total shows 0, non_cash_total shows their non-cash amounts

### Requirement: Session reconciliation MUST align cash sales total with events
The session reconciliation endpoint SHALL calculate pos_cash_sales_total by deducting change from cash payments, ensuring this total matches the expected_cash_total calculated from session events (CASH_SALE_IN - CHANGE_OUT).

#### Scenario: Reconciliation cash sales matches events calculation
- **WHEN** retrieving session reconciliation for a closed session
- **THEN** pos_cash_sales_total (from reports aggregation) equals (opening_float + CASH_SALE_IN events - CHANGE_OUT events)

#### Scenario: Session with no change given
- **WHEN** session has checkouts with no overpayment
- **THEN** pos_cash_sales_total equals the sum of all cash payments

#### Scenario: Session with multiple change transactions
- **WHEN** session has multiple checkouts with overpayment
- **THEN** pos_cash_sales_total correctly subtracts each checkout's change from its cash payment

