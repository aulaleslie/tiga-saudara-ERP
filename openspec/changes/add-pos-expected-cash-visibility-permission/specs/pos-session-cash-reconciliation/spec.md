## MODIFIED Requirements

### Requirement: Display opening float on session summary
The session summary page SHALL display the opening float amount (Saldo Awal) extracted from the OPEN_FLOAT cash events for the session.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Opening float displays correctly
- **WHEN** a user with balance visibility views a closed POS session summary
- **THEN** the Perhitungan Kas card displays opening float value calculated from all OPEN_FLOAT events with direction IN

#### Scenario: No opening float
- **WHEN** a user with balance visibility views a session with no opening float events
- **THEN** opening float displays as Rp 0.00

### Requirement: Display cash sales total on session summary
The session summary page SHALL display the total cash sales amount (Penjualan Kas) calculated from CASH_SALE_IN events for the session.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Cash sales amount displays
- **WHEN** a user with balance visibility views a closed session summary
- **THEN** the Perhitungan Kas card shows total cash sales from all CASH_SALE_IN events with direction IN

#### Scenario: No cash sales events
- **WHEN** a viewer with balance visibility accesses a session with no cash sales events
- **THEN** cash sales displays as Rp 0.00

### Requirement: Display safe drops total on session summary
The session summary page SHALL display the total amount picked up (Pengambilan Kas) extracted from SAFE_DROP_OUT events for the session.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Safe drops display
- **WHEN** a user with balance visibility views a closed session summary
- **THEN** the Perhitungan Kas card shows total safe drops from all SAFE_DROP_OUT events with direction OUT

#### Scenario: No safe drops
- **WHEN** a viewer with balance visibility accesses a session with no safe drop events
- **THEN** safe drops displays as Rp 0.00

### Requirement: Input non-cash transaction total
The session summary page SHALL provide an editable input field for the user to enter the total amount of non-cash transactions not captured in the cash event timeline.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Non-cash input available
- **WHEN** a user with balance visibility views a session summary
- **THEN** the Perhitungan Kas card contains an input field labeled "Penjualan Non-Kas" with placeholder "0.00"

#### Scenario: Non-cash amount is numeric
- **WHEN** a user with balance visibility enters a non-cash amount
- **THEN** the input accepts numeric values >= 0 with 2 decimal places

### Requirement: Display cash reconciliation formula
The session summary page SHALL calculate and display the cash reconciliation result using the formula: `opening_float + cash_sales + non_cash - safe_drops`.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Reconciliation calculates correctly
- **WHEN** a user with balance visibility enters values for opening float, cash sales, non-cash, and safe drops are displayed
- **THEN** the reconciliation card shows the calculated expected cash total
- **AND** the formula matches: opening_float + cash_sales + non_cash - safe_drops = expected_cash

#### Scenario: Reconciliation with no safe drops
- **WHEN** a viewer with balance visibility accesses a session with no safe drops (pickup = 0)
- **THEN** reconciliation calculates as: opening_float + cash_sales + non_cash = expected_cash

#### Scenario: Reconciliation with zero values
- **WHEN** a viewer with balance visibility accesses a session where all cash event components are zero
- **THEN** reconciliation shows Rp 0.00

### Requirement: Display expected cash vs actual comparison
The session summary page SHALL display both the expected cash total (calculated from reconciliation) and the actual total sales amount for comparison.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Expected cash matches sales total
- **WHEN** a user with balance visibility views a balanced session (expected_cash equals sales_total)
- **THEN** both values display in the reconciliation card with matching amounts

#### Scenario: Expected cash differs from sales total
- **WHEN** a viewer with balance visibility accesses a session whose expected cash does not match actual sales total
- **THEN** both values display so user can identify variance

### Requirement: Reconciliation card positioning
The Perhitungan Kas (cash reconciliation) card SHALL be positioned on the session summary page between the session overview and the timeline/transactions sections.

These display and input requirements SHALL apply only to viewers with `pos.sessions.viewExpectedCash`; other viewers SHALL NOT receive the revealing reconciliation card or its protected data.

#### Scenario: Card layout in summary
- **WHEN** a user with balance visibility views the session summary page
- **THEN** the Perhitungan Kas card is visible in a prominent location with proper spacing
- **AND** the card uses Bootstrap styling consistent with other cards on the page

### Requirement: Expected drawer cash SHALL be derived consistently from the cash-event ledger
The system SHALL calculate expected drawer cash by adding all cash-in events and subtracting all cash-out events, with neutral events having no monetary effect. The cached session expected-cash value and recalculated ledger value MUST use the same event amounts and directions.

#### Scenario: Opening float and checkout with change
- **WHEN** a session opens with Rp5,000,000 and later posts a Rp780,000 sale paid with Rp800,000 cash and Rp20,000 change
- **THEN** the expected drawer cash SHALL be Rp5,780,000
- **AND** recalculating from the ledger SHALL produce the same Rp5,780,000 value as the session cache

#### Scenario: Safe drop after cash checkout
- **WHEN** the session records cash tender and change for a checkout and subsequently records an approved safe drop
- **THEN** expected drawer cash SHALL equal opening float plus cash tender minus change minus the safe drop

#### Scenario: Replayed checkout finalization
- **WHEN** a posted checkout response is replayed using its existing idempotency key
- **THEN** no additional cash-in or change-out event SHALL be created
- **AND** expected drawer cash SHALL remain unchanged by the replay

#### Scenario: Approved outflow creates a negative balance
- **WHEN** cash-in events total Rp950000 and an approved Rp1000000 pickup is recorded as cash-out
- **THEN** ledger recalculation and the session cache SHALL both equal -Rp50000 without a zero floor

### Requirement: Session reporting distinguishes collections from sales
Session summaries and reconciliation SHALL separately display Kas Bon cash and non-cash collections. Cash thresholds, closing expected cash, and variance SHALL include net collection cash through the cash-event ledger. Expected cash SHALL NOT be a pickup limit. Revealing collection and reconciliation components SHALL be disclosed only to viewers with `pos.sessions.viewExpectedCash`. Collection SHALL not inflate new-sales totals or count multiple times because payment is split across generated Sales.

#### Scenario: Mixed collection and pickup
- **WHEN** opening float is Rp200,000 and a Rp100,000 collection comprises Rp60,000 cash and Rp40,000 transfer
- **THEN** expected cash is Rp260,000 before other movements, without imposing a pickup maximum
- **AND** the session reports to authorized balance viewers Rp60,000 cash collections and Rp40,000 non-cash collections separately from new sales

#### Scenario: Split-owner collection
- **WHEN** one collection creates payments across multiple generated Sales
- **THEN** session reporting counts the collection and its physical cash movement once

