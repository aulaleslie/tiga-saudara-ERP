## ADDED Requirements

### Requirement: Reprint receipt displays transaction time not reprint actor
Reprint receipts SHALL continue to log the reprint event while displaying the POS transaction date/time as customer-facing receipt information.

#### Scenario: Reprint logging remains intact
- **WHEN** a user accesses `/pos/transactions/{transaction}/receipt/reprint`
- **THEN** the system MUST create a `REPRINT` log entry with the reprinting user and timestamp
- **AND** the receipt output MUST NOT display `Terakhir dicetak oleh` or the reprinting user's name

#### Scenario: Reprint footer uses POS transaction time
- **WHEN** a reprint receipt is rendered for a completed POS transaction
- **THEN** the customer-facing footer date/time MUST use the completed checkout finalization time when available
- **AND** it MUST NOT use the latest reprint log timestamp

#### Scenario: Reprint of draft transaction uses transaction creation time
- **WHEN** a reprint receipt is rendered for a DRAFT or LOADED POS transaction
- **THEN** the customer-facing footer date/time MUST use the POS transaction creation time
- **AND** it MUST NOT use the latest print or reprint log timestamp
