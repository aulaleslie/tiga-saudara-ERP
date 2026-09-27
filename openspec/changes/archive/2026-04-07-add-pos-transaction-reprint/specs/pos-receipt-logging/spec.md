## ADDED Requirements

### Requirement: Print logging supports both checkouts and transactions
The print logging system SHALL support logging prints for both POS checkouts and draft transactions. Each print event SHALL be tracked in a unified log with the printed user and timestamp.

#### Scenario: Checkout print is logged
- **WHEN** a checkout receipt is printed or reprinted
- **THEN** a log entry is created with:
  - Associated checkout ID
  - Print type (PRINT or REPRINT)
  - User ID who initiated the print
  - Timestamp of the print action

#### Scenario: Transaction print is logged
- **WHEN** a transaction receipt is printed or reprinted
- **THEN** a log entry is created with:
  - Associated transaction (either through NULL checkout_id or transaction reference)
  - Print type (PRINT or REPRINT)
  - User ID who initiated the print
  - Timestamp of the print action

#### Scenario: Print logs are queryable by transaction
- **WHEN** retrieving print logs for a transaction
- **THEN** the system can efficiently query all print events (PRINT and REPRINT) related to that transaction

#### Scenario: Print logs include user information
- **WHEN** retrieving a print log
- **THEN** the system can access the user who printed without additional queries (eager loaded)

### Requirement: Unified print log table schema
The print logging table SHALL accommodate both checkout and transaction prints without duplicating logging logic.

#### Scenario: Nullable checkout reference
- **WHEN** logging a transaction print
- **THEN** the `pos_checkout_id` column is NULL
- **AND** the log is retrievable and queryable without errors

#### Scenario: Non-null checkout reference for checkouts
- **WHEN** logging a checkout print
- **THEN** the `pos_checkout_id` column contains the checkout ID
- **AND** the log maintains referential integrity
