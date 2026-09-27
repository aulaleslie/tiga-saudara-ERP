## MODIFIED Requirements

### Requirement: Session summary endpoint returns context-appropriate data
The /pos/sessions/{session}/summary endpoint SHALL return session summary data with content adapted based on whether the session was created with or without a terminal. Both session types return the same data structure but with different content populated based on terminal presence.

#### Scenario: Non-terminal session summary response
- **WHEN** user requests summary for a non-terminal session (terminal_id is null)
- **THEN** the response includes: session_id, status, cashier_user_id, cashier_name, terminal_id (null), expected_cash_total (0), sales_total, duration, transactions (array of PosTransaction records)
- **AND** the response does NOT include: cash_events, threshold_value, is_threshold_breached
- **AND** the transactions array contains up to 50 most recent transaction records with: id, code, owner_name, amount, created_at timestamp

#### Scenario: Terminal session summary response
- **WHEN** user requests summary for a terminal session (terminal_id is not null)
- **THEN** the response includes all fields: session_id, status, cashier_user_id, cashier_name, terminal_id, terminal_code, terminal_name, expected_cash_total, sales_total, duration, threshold_value, is_threshold_breached, transactions (array of PosCheckout records), cash_events
- **AND** the transactions array contains up to 50 most recent checkout records with: id, receipt_number, cashier, payment_method, amount, finalized_at timestamp
- **AND** cash_events array shows all cash events in reverse chronological order

#### Scenario: Cashier name is included in all summary responses
- **WHEN** user requests a session summary (any session type)
- **THEN** the response includes a 'cashier_name' field with the cashier's full name
- **AND** the cashier_user_id is still included for backend use
- **AND** the cashier_name is never null or undefined

#### Scenario: Summary endpoint handles mixed transaction types
- **WHEN** loading a non-terminal session created during operation where transactions were later finalized into checkouts
- **THEN** the response includes transaction records (not checkouts)
- **AND** checkouts are NOT included in the transactions array for non-terminal sessions

### Requirement: Session summary view displays content conditionally
The session summary Blade template SHALL render different UI sections based on the presence of a terminal in the session.

#### Scenario: Non-terminal session displays simplified card
- **WHEN** rendering session summary for a non-terminal session
- **THEN** the Ikhtisar Sesi card shows only: Status, Cashier Name, Duration, Total Amount
- **AND** fields related to cash threshold and expected cash are NOT rendered

#### Scenario: Terminal session displays full details card
- **WHEN** rendering session summary for a terminal session
- **THEN** the Ikhtisar Sesi card shows: Terminal Code/Name, Cashier Name, Status, Duration, Total Penjualan, Ekspektasi Kas, Ambang Batas, and threshold breach alert (if applicable)
- **AND** all cash-related fields are rendered and visible
