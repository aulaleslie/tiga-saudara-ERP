# pos-change-outflow-tracking Specification

## Purpose
Track change given to customers during POS transactions as cash outflow events that reduce expected cash total in a session.

## Requirements

### Requirement: Change recorded as outflow event
When a POS checkout with cash component is finalized and change is given to the customer, the system SHALL create a CHANGE_OUT event in pos_session_cash_events table that reduces expected_cash_total.

#### Scenario: Single payment with cash and change
- **WHEN** a checkout is finalized with a single cash payment, grand total 12,000,000, and customer pays 13,000,000 (1,000,000 change)
- **THEN** a CHANGE_OUT event is created with amount 1,000,000 and direction OUT

#### Scenario: Multi-payment with cash component and change
- **WHEN** a checkout is finalized with multi-payment (8,000,000 non-cash + 5,000,000 cash), grand total 12,000,000, customer pays 13,000,000 cash plus 8,000,000 non-cash (1,000,000 change from cash)
- **THEN** a CHANGE_OUT event is created with amount 1,000,000 and direction OUT

#### Scenario: No change given
- **WHEN** a checkout is finalized where payment amount equals grand total (no change)
- **THEN** no CHANGE_OUT event is created

#### Scenario: Non-cash payment without change
- **WHEN** a checkout is finalized with only non-cash payment method
- **THEN** no CHANGE_OUT event is created (no cash component means no change)

### Requirement: Expected cash reflects physical drawer contents
The expected_cash_total in a session SHALL be calculated as: opening_float + cash_in - change_out - safe_drops, correctly representing what should physically be in the drawer.

#### Scenario: Expected cash with change
- **WHEN** viewing a session with opening float 2,000,000, one cash sale of 5,000,000 with 1,000,000 change, and no safe drops
- **THEN** expected_cash_total is 6,000,000 (2M + 5M - 1M)

#### Scenario: Expected cash with multiple transactions
- **WHEN** viewing a session with opening float 2,000,000, two transactions (first: 5,000,000 cash with 1,000,000 change; second: 3,000,000 non-cash only), no safe drops
- **THEN** expected_cash_total is 6,000,000 (2M + 5M - 1M, second transaction contributes 0 cash)

#### Scenario: Expected cash with safe drops and change
- **WHEN** viewing a session with opening float 2,000,000, one cash sale of 5,000,000 with 1,000,000 change, safe drop of 2,000,000
- **THEN** expected_cash_total is 4,000,000 (2M + 5M - 1M - 2M)

### Requirement: Change event has correct metadata
A CHANGE_OUT event SHALL include event_type, direction, amount, reference to the checkout, and the timestamp of finalization.

#### Scenario: CHANGE_OUT event structure
- **WHEN** a CHANGE_OUT event is created for a checkout finalization
- **THEN** event has: event_type='CHANGE_OUT', direction='OUT', amount > 0, reference_type='pos_checkout', reference_id pointing to the checkout, occurred_at set to finalization time

#### Scenario: Change event visibility in session summary
- **WHEN** querying cash events for a session via PosSessionSummaryService
- **THEN** CHANGE_OUT events are included in the cash_events timeline alongside CASH_SALE_IN and SAFE_DROP_OUT events
