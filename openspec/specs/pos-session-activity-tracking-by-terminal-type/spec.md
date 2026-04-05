# pos-session-activity-tracking-by-terminal-type Specification

## Purpose
TBD - created by archiving change standardize-pos-session-metrics-and-activity. Update Purpose after archive.
## Requirements
### Requirement: Terminal Sessions Display Checkout Completion Metrics
Sessions WITH a terminal SHALL display transaction metrics based on CASH_SALE_IN events (checkout completions). This reflects the cashier-driven cash accounting workflow.

#### Scenario: Terminal session shows cash event counts
- **WHEN** viewing a session with a terminal assigned
- **THEN** the Metrik column displays the count of CASH_SALE_IN events for that session

#### Scenario: Terminal session shows last cash activity
- **WHEN** viewing a session with a terminal assigned
- **THEN** the Aktivitas Terakhir column displays the timestamp of the most recent CASH_SALE_IN event

### Requirement: Non-Terminal Sessions Display Transaction Creation Metrics
Sessions WITHOUT a terminal SHALL display transaction metrics based on total PosTransaction records created in the session, regardless of transaction status (DRAFT, LOADED, COMPLETED, CANCELLED). This reflects the floor staff transaction creation workflow.

#### Scenario: Non-terminal session shows all created transactions
- **WHEN** viewing a session without a terminal assigned
- **THEN** the Metrik column displays the count of all PosTransaction records with source_pos_session_id matching this session

#### Scenario: Non-terminal session shows last transaction creation
- **WHEN** viewing a session without a terminal assigned
- **THEN** the Aktivitas Terakhir column displays the created_at timestamp of the most recently created PosTransaction for this session

### Requirement: Consistent Activity Timestamp Across All Session Statuses
The Aktivitas Terakhir column SHALL display activity timestamps for all session statuses (OPEN, CLOSED, CLOSING, FINALIZED), not just OPEN sessions.

#### Scenario: Open sessions show activity in HH:mm format
- **WHEN** viewing an OPEN session (terminal or non-terminal)
- **THEN** the Aktivitas Terakhir timestamp is formatted as HH:mm (e.g., "14:32")

#### Scenario: Closed sessions show activity in full datetime format
- **WHEN** viewing a CLOSED, CLOSING, or FINALIZED session (terminal or non-terminal)
- **THEN** the Aktivitas Terakhir timestamp is formatted as DD/MM/YYYY HH:mm (e.g., "05/04/2026 14:32")

#### Scenario: Sessions with no activity show dash
- **WHEN** viewing a session that has no cash events (terminal) or no transactions (non-terminal)
- **THEN** the Aktivitas Terakhir column displays "-"

### Requirement: Consistent Metrik Column Display Across All Session Statuses
The Metrik column SHALL display transaction counts for all session statuses (OPEN, CLOSED, CLOSING, FINALIZED), removing status-dependent conditional logic.

#### Scenario: Open terminal session shows checkout count
- **WHEN** viewing an OPEN session with a terminal
- **THEN** the Metrik column displays the count of CASH_SALE_IN events

#### Scenario: Closed terminal session shows checkout count
- **WHEN** viewing a CLOSED session with a terminal
- **THEN** the Metrik column displays the count of CASH_SALE_IN events (same as OPEN sessions)

#### Scenario: Open non-terminal session shows transaction count
- **WHEN** viewing an OPEN session without a terminal
- **THEN** the Metrik column displays the count of all PosTransaction records created in this session

#### Scenario: Closed non-terminal session shows transaction count
- **WHEN** viewing a CLOSED session without a terminal
- **THEN** the Metrik column displays the count of all PosTransaction records created in this session (same as OPEN sessions)

### Requirement: Session List Query Optimization
The backend query for the sessions list SHALL pre-aggregate transaction counts and activity timestamps using withCount and withMax to avoid N+1 queries.

#### Scenario: Query loads terminal session metrics efficiently
- **WHEN** loading the sessions list page
- **THEN** the query includes withCount('cashEvents as transaction_count') filtered by CASH_SALE_IN and withMax('cashEvents as last_cash_activity') in a single query round-trip

#### Scenario: Query loads non-terminal session metrics efficiently
- **WHEN** loading the sessions list page
- **THEN** the query includes withCount('transactions as draft_transaction_count') and withMax('transactions as last_transaction_created') in the same query round-trip

#### Scenario: No N+1 queries for 15 paginated sessions
- **WHEN** loading a paginated page with 15 sessions
- **THEN** the database executes exactly 2-3 queries total (sessions + eager loads), not one query per session

