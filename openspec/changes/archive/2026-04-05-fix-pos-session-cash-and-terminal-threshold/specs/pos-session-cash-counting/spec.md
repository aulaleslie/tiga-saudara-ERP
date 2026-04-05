## ADDED Requirements

### Requirement: Close modal collects actual cash counted
The system SHALL provide a numeric input field in the close modal allowing the cashier/admin to enter the total cash physically counted before transition to CLOSED status.

#### Scenario: Valid cash amount entered
- **WHEN** user enters valid positive numeric amount (e.g., 500000) in close modal cash field and clicks "Tutup Sesi"
- **THEN** the amount is persisted to `pos_sessions.counted_cash_total` and session transitions to CLOSED status

#### Scenario: Field is required
- **WHEN** user attempts to close without entering cash amount
- **THEN** form validation error is shown and session remains in OPEN status

#### Scenario: Negative or invalid amounts rejected
- **WHEN** user enters negative, zero, or non-numeric value
- **THEN** form validation error is shown and close operation is prevented

### Requirement: Closed sessions display actual cash counted
The system SHALL display the actual cash counted (from `counted_cash_total`) in the "Kas" column for CLOSED, CLOSING, and FINALIZED sessions.

#### Scenario: Closed session shows counted cash
- **WHEN** viewing sessions list and session status is CLOSED
- **THEN** "Kas" column displays `counted_cash_total` formatted as currency (not NULL/blank)

#### Scenario: Finalized session shows counted cash
- **WHEN** viewing sessions list and session status is FINALIZED
- **THEN** "Kas" column still displays `counted_cash_total` (preserved from close)

#### Scenario: Open session shows expected cash
- **WHEN** viewing sessions list and session status is OPEN
- **THEN** "Kas" column displays `expected_cash_total` (expected cash in drawer)

### Requirement: Variance calculated from actual cash
The system SHALL calculate variance as (counted_cash_total - expected_cash_total) when closing and persist it.

#### Scenario: Variance calculation on close
- **WHEN** session closes with counted_cash_total = 1,500,000 and expected_cash_total = 1,450,000
- **THEN** variance_total = 50,000 (positive variance)

#### Scenario: Zero variance
- **WHEN** counted_cash_total equals expected_cash_total
- **THEN** variance_total = 0 and "Metrik" column displays green "0"

#### Scenario: Negative variance
- **WHEN** counted_cash_total = 1,400,000 and expected_cash_total = 1,450,000
- **THEN** variance_total = -50,000 (shortfall) and "Metrik" column displays red "-50,000"
