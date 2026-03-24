# pos-session-expected-cash-calculation Specification

## Purpose
Define how expected cash total is calculated in a POS session to accurately reflect the physical cash that should be in the drawer.

## Requirements

### Requirement: Expected cash calculation includes all cash events
The expected_cash_total for a session SHALL be calculated by iterating through all pos_session_cash_events in chronological order, applying the direction (IN/OUT) of each event, and cumulatively summing to produce the final expected amount.

#### Scenario: Calculate from multiple event types
- **WHEN** a session has events: OPEN_FLOAT (IN, 2M), CASH_SALE_IN (IN, 5M), CHANGE_OUT (OUT, 1M), SAFE_DROP_OUT (OUT, 2M)
- **THEN** expected_cash_total = 2M + 5M - 1M - 2M = 4M

#### Scenario: Handle DIRECTION_NEUTRAL events
- **WHEN** a session has a DIRECTION_NEUTRAL event (if applicable)
- **THEN** neutral events are skipped in the summation without affecting the total

#### Scenario: Preserve calculation accuracy with decimal amounts
- **WHEN** cash events have amounts with decimal precision (e.g., 12,500.50)
- **THEN** amounts are rounded to 2 decimal places before summing; final total is rounded to 2 decimals

### Requirement: Change outflow reduces expected cash
When calculating expected_cash_total, CHANGE_OUT events (DIRECTION_OUT) SHALL subtract from the running total, properly accounting for change given to customers.

#### Scenario: Change reduces running total
- **WHEN** iterating events in order: OPEN_FLOAT +2M, CASH_SALE_IN +5M, CHANGE_OUT -1M
- **THEN** running totals are: 2M → 7M → 6M; final expected_cash_total is 6M

#### Scenario: Multiple changes reduce total correctly
- **WHEN** session has two transactions with change: first 5M cash with 1M change, second 3M cash with 500k change
- **THEN** expected_cash_total = opening + 5M - 1M + 3M - 500k = opening + 6.5M

### Requirement: PosSessionExpectedCashCalculator supports CHANGE_OUT
The PosSessionExpectedCashCalculator service SHALL correctly handle CHANGE_OUT event type with DIRECTION_OUT without throwing exceptions.

#### Scenario: Calculator handles CHANGE_OUT event type
- **WHEN** PosSessionExpectedCashCalculator processes a session with CHANGE_OUT events
- **THEN** calculation completes successfully without unknown direction errors; amount is subtracted from total

#### Scenario: Calculation is idempotent
- **WHEN** calling PosSessionExpectedCashCalculator.calculate() twice on same session
- **THEN** both calls return identical expected_cash_total values
