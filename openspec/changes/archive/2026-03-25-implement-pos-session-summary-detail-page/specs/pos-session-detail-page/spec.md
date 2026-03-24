## ADDED Requirements

### Requirement: Display session overview header
The detail page SHALL display a header section with session metadata including terminal code and name, cashier name, session status (OPEN/CLOSED/CLOSING/FINALIZED), session duration, opened/closed timestamps, and key metrics (opening float total, expected cash total, cash threshold, is threshold breached).

#### Scenario: View active session header
- **WHEN** user views a detail page for an OPEN session
- **THEN** the header shows "AKTIF" badge in green, session duration as "X jam Y menit", and expected cash (not counted cash)

#### Scenario: View closed session header
- **WHEN** user views a detail page for a CLOSED or FINALIZED session
- **THEN** the header shows status badge (SELESAI/FINALIZED), closed timestamp, and actual counted cash (not expected)

---

### Requirement: Display cash events timeline with filtering
The detail page SHALL display all cash events in reverse chronological order (newest first). Each event SHALL show event type (OPEN_FLOAT, CASH_SALE_IN, SAFE_DROP_OUT, CHANGE_OUT, CLOSE_COUNT, FINALIZE_COUNT), direction (IN, OUT, NEUTRAL), amount, performer name, approver name (if present), notes, and timestamp. The page SHALL provide filter buttons to toggle visibility by event type.

#### Scenario: View all cash events
- **WHEN** user loads the detail page
- **THEN** all cash events are displayed in a timeline, sorted by timestamp descending (newest first)

#### Scenario: Filter events by type
- **WHEN** user clicks a filter button for event type (e.g., "SAFE_DROP_OUT")
- **THEN** only events of that type remain visible; other types are hidden
- **AND** the button is highlighted/active to show current filter

#### Scenario: Clear filter
- **WHEN** user clicks the "All Events" or active filter button again
- **THEN** all events become visible again

#### Scenario: Event with missing performer or approver
- **WHEN** a cash event has no performer or approver (null FK)
- **THEN** system displays "Unknown" or a placeholder in place of the user name

---

### Requirement: Display transaction ledger with drill-down
The detail page SHALL display the last 50 transactions in a table with columns: receipt number, amount, payment method, cashier name, and finalized timestamp. Each transaction row SHALL be clickable. Clicking a row SHALL open a modal showing full checkout details. The ledger SHALL display aggregates: total transaction count and total amount.

#### Scenario: View transaction list
- **WHEN** user scrolls to the transactions section
- **THEN** a table appears with up to 50 rows, sorted by finalized timestamp descending (most recent first)

#### Scenario: Click transaction to open details modal
- **WHEN** user clicks a transaction row
- **THEN** a modal popup opens displaying full checkout details (receipt, items, amounts, payment info, etc.)

#### Scenario: Close transaction detail modal
- **WHEN** user clicks the close button or clicks outside the modal
- **THEN** the modal closes and user remains on the session detail page

#### Scenario: View transaction aggregates
- **WHEN** user views the transaction ledger
- **THEN** the ledger footer displays "Total: X transactions" and "Total Amount: Rp YYYY"

#### Scenario: Session with zero transactions
- **WHEN** a session has no POSTED checkouts
- **THEN** the transaction section displays "No transactions found" and shows 0 aggregates

---

### Requirement: Display conditional action buttons
The detail page SHALL display action buttons (Close, Finalize, Admin Close) based on session status and user permissions. Close and Finalize buttons SHALL reuse existing modal handlers. Buttons SHALL be disabled or hidden if user lacks the required permission or session status does not allow the action.

#### Scenario: OPEN session with pos.sessions.close permission
- **WHEN** user views an OPEN session and has pos.sessions.close permission
- **THEN** a "Close Session" button is visible and enabled

#### Scenario: CLOSED session with pos.supervisor.approval permission
- **WHEN** user views a CLOSED session and has pos.supervisor.approval permission
- **THEN** a "Finalize" button is visible and enabled

#### Scenario: User without permission
- **WHEN** user lacks pos.sessions.close permission
- **THEN** all action buttons are hidden or disabled

#### Scenario: Click action button opens existing modal
- **WHEN** user clicks "Close" or "Finalize" button
- **THEN** the corresponding modal from the sessions list handlers opens, fetches session data, and populates fields

---

### Requirement: Authorize access to session detail
The detail page SHALL enforce authorization: the authenticated user must either be the session owner (cashier_user_id) or have pos.sessions.view permission. If neither condition is met, the page SHALL return a 403 error.

#### Scenario: Session owner views own session
- **WHEN** the authenticated user is the cashier_user_id
- **THEN** the page loads and displays full session details

#### Scenario: User with pos.sessions.view permission views any session
- **WHEN** the authenticated user has pos.sessions.view permission (not the owner)
- **THEN** the page loads and displays full session details

#### Scenario: Unauthorized user
- **WHEN** the authenticated user is neither the owner nor has pos.sessions.view permission
- **THEN** a 403 Forbidden error is returned with message "Not authorized to view POS session summary."

---

### Requirement: Back navigation
The detail page SHALL display a back button that returns the user to the sessions list page (pos.sessions.index).

#### Scenario: Click back button
- **WHEN** user clicks the back button
- **THEN** browser navigates to /pos/sessions (the index page)
