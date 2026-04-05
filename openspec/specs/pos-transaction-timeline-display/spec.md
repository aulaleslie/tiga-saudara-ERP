## ADDED Requirements

### Requirement: Session summary displays transaction timeline for non-terminal sessions
For non-terminal POS sessions, the session summary page SHALL display a timeline of transactions created during the session, showing transaction details in chronological order with timestamps and amounts.

#### Scenario: Transaction timeline displays all transactions
- **WHEN** user views a non-terminal session summary
- **THEN** the Timeline Transaksi section displays all PosTransaction records created in that session
- **AND** transactions are sorted by creation timestamp (newest first)
- **AND** each transaction shows: code, owner name, amount, and timestamp (HH:mm format for same-day, dd/mm/YYYY HH:mm for past dates)

#### Scenario: Transaction row styling indicates information clearly
- **WHEN** user views the transaction timeline
- **THEN** each transaction row displays the code in bold for easy scanning
- **AND** the amount is displayed with currency formatting
- **AND** the owner name is shown for transaction attribution
- **AND** the timestamp is shown in muted text

#### Scenario: Transaction timeline handles large result sets
- **WHEN** a session has more than 50 transactions
- **THEN** only the 50 most recent transactions are displayed in the timeline
- **AND** a footer indicates the total transaction count and total amount for ALL transactions in the session
- **AND** users can access the full transaction list through the Transaksi POS menu

### Requirement: Transaction timeline provides clear empty state
When a non-terminal session has no transactions, the timeline SHALL display a helpful empty state message.

#### Scenario: Empty transaction timeline message
- **WHEN** user views a non-terminal session with no transactions
- **THEN** the Timeline Transaksi section displays the message "Belum ada transaksi diposting."
- **AND** the message is displayed in muted color in the center of the section
- **AND** no other timeline content is shown
