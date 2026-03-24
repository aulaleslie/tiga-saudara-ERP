## MODIFIED Requirements

### Requirement: checkoutFinalize calculates change from total paid amount
The finalization logic SHALL calculate the `change_total` based on the difference between the total amount paid across all payment methods (`paid_total`) and the grand total of the checkout. For multi-payment checkouts, this calculation MUST NOT be limited to only the cash component.

#### Scenario: Change calculation for mixed payments
- **WHEN** user pays MANDIRI 10,000,000 and CASH 3,000,000 for a 12,000,000 grand total
- **THEN** `change_total` is calculated as 1,000,000 (13,000,000 total paid - 12,000,000 grand total)

### Requirement: Accurate cash event emission for change
When a checkout results in change being given to the customer, the system SHALL emit a `PosSessionCashEvent` with `event_type: EVENT_CHANGE_OUT` and `direction: DIRECTION_OUT`. This event MUST be emitted for both single-payment and multi-payment checkouts to ensure the `expected_cash_total` is correctly decremented.

#### Scenario: Change event for multi-payment
- **WHEN** a multi-payment checkout is finalized with 1,000,000 change
- **THEN** a `PosSessionCashEvent` is created with type `EVENT_CHANGE_OUT`, amount 1,000,000, and direction `OUT`
