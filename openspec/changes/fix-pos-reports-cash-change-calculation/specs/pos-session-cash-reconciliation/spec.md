## MODIFIED Requirements

### Requirement: Reconciliation cash sales total MUST deduct change from cash payments
The session reconciliation endpoint SHALL calculate pos_cash_sales_total by aggregating cash payment amounts from pos_checkout_payments and deducting change_total from pos_checkouts, ensuring alignment with session cash event calculations (opening_float + CASH_SALE_IN - CHANGE_OUT).

#### Scenario: Reconciliation matches session events with single-payment checkouts
- **WHEN** retrieving session reconciliation for a closed session with single-payment checkouts
- **THEN** pos_cash_sales_total (from aggregating cash payments minus change) matches expected_cash_total (from CASH_SALE_IN and CHANGE_OUT events)

#### Scenario: Reconciliation handles multi-payment with overpayment
- **WHEN** session contains a multi-payment checkout (50,000 cash + 30,000 QRIS for 75,000 transaction)
- **THEN** pos_cash_sales_total includes 45,000 (50,000 cash minus 5,000 change) plus any other cash amounts

#### Scenario: Reconciliation with no change transactions
- **WHEN** session has checkouts with exact payment (no overpayment)
- **THEN** pos_cash_sales_total equals sum of all cash payments (no change deduction for those checkouts)

#### Scenario: Reconciliation variance detection
- **WHEN** pos_cash_sales_total does not match expected_cash_total
- **THEN** mismatch is recorded in the reconciliation response with details for investigation

#### Scenario: Session reconciliation includes all change events
- **WHEN** session closed after customers received change from multiple transactions
- **THEN** expected_cash_total calculation correctly subtracts all CHANGE_OUT event amounts from cash inflows
