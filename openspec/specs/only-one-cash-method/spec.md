## ADDED Requirements

### Requirement: Global Uniqueness for Cash Payment Method
The system must ensure that only one payment method can have the `is_cash` flag set to `true` at any given time. This restriction applies globally across the `payment_methods` table.

#### Scenario: Success on first cash method creation
- **WHEN** creating a new payment method with `is_cash = true` AND no existing record has `is_cash = true`.
- **THEN** validation passes and the record is saved.

#### Scenario: Failure on duplicate cash method creation
- **WHEN** creating a new payment method with `is_cash = true` AND at least one other record already has `is_cash = true`.
- **THEN** validation fails with a specific error message.

#### Scenario: Success on updating existing cash method to non-cash
- **WHEN** updating the single record where `is_cash = true` to set `is_cash = false`.
- **THEN** validation passes.

#### Scenario: Failure on enabling cash flag when another exists
- **WHEN** updating a non-cash record to set `is_cash = true` AND a different record already has `is_cash = true`.
- **THEN** validation fails.

#### Scenario: Success on updating current cash method
- **WHEN** updating a record where `is_cash = true` (e.g. changing its name) AND it remains `is_cash = true`.
- **THEN** validation ignores itself and passes.
