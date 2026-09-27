## Purpose

The system SHALL enable users to reprint transaction receipts with audit logging and display integrity safeguards.
## Requirements
### Requirement: User can reprint transaction receipts
Users with the `pos.receipts.reprint` permission SHALL be able to reprint receipts for any POS transaction (regardless of status: DRAFT, LOADED, COMPLETED, or CANCELLED). Each reprint action SHALL be logged with the user ID and timestamp.

#### Scenario: Reprint button visible in transaction list for permitted user
- **WHEN** a user with `pos.receipts.reprint` permission views the transaction list
- **THEN** a "Reprint" button appears in the action column for each transaction

#### Scenario: Reprint button hidden for unpermitted user
- **WHEN** a user without `pos.receipts.reprint` permission views the transaction list
- **THEN** no "Reprint" button appears in the action column

#### Scenario: Reprint button visible in transaction detail for permitted user
- **WHEN** a user with `pos.receipts.reprint` permission views a transaction detail page
- **THEN** a "Reprint" button appears in the card header next to other action buttons

#### Scenario: Reprint button triggers receipt print view
- **WHEN** a user clicks the "Reprint" button on a transaction
- **THEN** the system opens the receipt view for that transaction in a new window/tab

#### Scenario: Reprint logs are tracked
- **WHEN** a user reprints a transaction receipt
- **THEN** a log entry is created with:
  - Print type: "REPRINT"
  - User ID of the person who reprinted
  - Timestamp of the reprint action
  - Reference to the transaction (or associated checkout if applicable)

### Requirement: Initial receipt view logs as PRINT
When a user views a transaction receipt for the first time (not via the dedicated reprint endpoint), it SHALL be logged with print type "PRINT" to distinguish from explicit reprints.

#### Scenario: Receipt view logs initial print
- **WHEN** a user views a transaction receipt via `/pos/transactions/{transaction}/receipt`
- **THEN** the system logs this as a "PRINT" event with the user ID and timestamp

#### Scenario: Reprint endpoint logs reprint
- **WHEN** a user accesses `/pos/transactions/{transaction}/receipt/reprint`
- **THEN** the system logs this as a "REPRINT" event with the user ID and timestamp

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

### Requirement: Robust Customer Identity Display on Reprint
Reprint receipts SHALL use the same robust customer identity display logic as the main POS receipt. It MUST safely ignore empty strings (`""`) and combine `contact_name` and `company_name` (or `customer_name`) appropriately to provide maximum context, ensuring a blank name is never printed due to an empty string in the primary fallback field.

#### Scenario: Customer name is fully reconstructed on reprint
- **WHEN** a user reprints a transaction receipt
- **THEN** the system MUST evaluate the customer's `contact_name`, `company_name`, and `customer_name` using empty-string-safe logic
- **AND** it MUST format the output as "Contact Name - Company Name" if both are present and distinct

