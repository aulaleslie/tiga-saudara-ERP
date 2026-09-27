## ADDED Requirements

### Requirement: Non-terminal session summary displays transaction-focused information
When viewing a POS session summary for a session created without a terminal, the page SHALL display information relevant to transaction drafting workflows instead of cash-related fields. The summary card SHALL show only: Session Status, Cashier Name, Transaction Count, and Total Transaction Amount. The cash timeline SHALL be hidden.

#### Scenario: View non-terminal session summary card
- **WHEN** user navigates to a non-terminal session summary page
- **THEN** the Ikhtisar Sesi card displays: Status badge, Cashier name (not ID), "Durasi Sesi" duration, "Total Penjualan" total amount, and "Total Transaksi" count
- **AND** fields like "Ekspektasi Kas", "Ambang Batas", and cash threshold alert are NOT displayed

#### Scenario: Cash timeline hidden for non-terminal sessions
- **WHEN** user views a non-terminal session summary
- **THEN** the "Timeline Kas" section is not rendered
- **AND** a "Timeline Transaksi" section is displayed instead showing transactions created in the session

#### Scenario: Cashier name displayed instead of ID
- **WHEN** user views a session summary (terminal or non-terminal)
- **THEN** the Ikhtisar Sesi card displays the cashier's full name (e.g., "John Doe")
- **AND** the cashier user ID number is not displayed

### Requirement: Transaction timeline displays drafts for non-terminal sessions
When viewing a non-terminal session summary, a transaction timeline SHALL display all PosTransaction records created during that session, showing each transaction's code, amount, owner, creation timestamp, and status.

#### Scenario: View transaction timeline for non-terminal session
- **WHEN** user views a non-terminal session summary
- **THEN** the Timeline Transaksi section displays a list of transactions sorted by creation time (newest first)
- **AND** each transaction row shows: Receipt/Transaction Code, Cashier Name, Amount, and Timestamp

#### Scenario: Empty transaction timeline when no transactions exist
- **WHEN** user views a non-terminal session that has no transactions
- **THEN** the Timeline Transaksi section displays "Belum ada transaksi diposting."

### Requirement: Clicking transaction navigates to transaction detail page
When a user clicks on a transaction in the session summary, the browser SHALL navigate directly to the transaction's detail page instead of opening a modal dialog.

#### Scenario: Navigate to transaction detail
- **WHEN** user clicks on a transaction row in the timeline
- **THEN** the browser navigates to `/pos/transactions/{id}` where {id} is the transaction ID

#### Scenario: Transaction detail page shows transaction information
- **WHEN** user navigates to a transaction from session summary
- **THEN** the transaction detail page loads with full transaction information (owner, customer, items, totals)
- **AND** available actions (Load, Cancel) are displayed based on user permissions and transaction status

### Requirement: Terminal session summary remains unchanged
Cash timeline and all related fields SHALL continue to display for sessions created with a terminal. The existing terminal session display behavior is preserved.

#### Scenario: View terminal session summary
- **WHEN** user views a session summary for a terminal-based session
- **THEN** the "Timeline Kas" section displays cash events (OPEN_FLOAT, CASH_SALE_IN, SAFE_DROP_OUT)
- **AND** the Ikhtisar Sesi card shows all fields: Terminal code/name, Cashier name, Status, Duration, Total Penjualan, Ekspektasi Kas, and Ambang Batas
- **AND** transaction list shows checkouts (finalized sales) instead of transaction drafts

## MODIFIED Requirements

### Requirement: Session summary service loads complete session data
The PosSessionSummaryService::getSummary() method SHALL return complete session information including cashier name, terminal details, and appropriate transaction records based on session type.

#### Scenario: Service returns cashier name
- **WHEN** PosSessionSummaryService loads a session summary
- **THEN** the returned array includes 'cashier_name' with the cashier's full name
- **AND** does NOT include raw cashier_user_id in display-ready format

#### Scenario: Service loads transactions for non-terminal sessions
- **WHEN** PosSessionSummaryService loads a non-terminal session (terminal_id is null)
- **THEN** the returned array includes 'transactions' array populated with PosTransaction records (code, owner, amount, created_at, status)
- **AND** up to 50 most recent transactions are included
- **AND** cash events are NOT included in the response

#### Scenario: Service loads checkouts for terminal sessions
- **WHEN** PosSessionSummaryService loads a terminal session (terminal_id is not null)
- **THEN** the returned array includes 'transactions' array populated with PosCheckout records (receipt_number, cashier, amount, finalized_at)
- **AND** up to 50 most recent checkouts are included
- **AND** 'cash_events' array is included showing all cash timeline events
