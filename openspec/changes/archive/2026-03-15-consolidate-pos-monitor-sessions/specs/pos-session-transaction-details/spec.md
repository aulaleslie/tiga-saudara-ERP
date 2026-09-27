## ADDED Requirements

### Requirement: Full transaction list in session details
The session details view SHALL display all transactions (checkouts/sales) that occurred within the session.

#### Scenario: Load transactions from session summary
- **WHEN** user opens session details and scrolls to transaction section
- **THEN** system displays:
  - List of all checkouts/sales with transaction ID
  - Amount for each transaction
  - Payment method used
  - Timestamp of each transaction
  - Cashier/operator who processed the transaction

#### Scenario: Handle session with no transactions
- **WHEN** user opens details for a session with zero transactions
- **THEN** system displays "No transactions recorded in this session"

### Requirement: Cash event timeline in session details
The session details view SHALL display chronological timeline of all cash movements (safe drops, pickups, discrepancies).

#### Scenario: View cash event sequence
- **WHEN** user opens session details and views cash event timeline
- **THEN** system displays:
  - Event type (Safe Drop, Supervisor Pickup, etc.)
  - Amount involved
  - Timestamp of event
  - User/supervisor who performed the event
  - Notes or reason if provided

#### Scenario: Timeline is chronologically ordered
- **WHEN** user views the cash event timeline
- **THEN** events are displayed in chronological order (oldest first or newest first, consistent ordering)

### Requirement: Summary endpoint includes transaction details
The existing `/pos/sessions/{session}/summary` endpoint SHALL return transaction list and cash event timeline.

#### Scenario: Fetch session summary with transactions
- **WHEN** client calls GET `/pos/sessions/{session}/summary`
- **THEN** response includes:
  - Current session metrics (opening float, expected cash, status)
  - Array of transactions with IDs, amounts, methods, timestamps
  - Array of cash events with types, amounts, users, timestamps
  - All data paginated or limited if dataset is very large

#### Scenario: Authorization on summary endpoint
- **WHEN** user requests summary for a session
- **THEN** system enforces:
  - User can view if they are the cashier of that session
  - OR user has `pos.monitor.access` (or equiv. permission)
  - Returns 403 if not authorized
