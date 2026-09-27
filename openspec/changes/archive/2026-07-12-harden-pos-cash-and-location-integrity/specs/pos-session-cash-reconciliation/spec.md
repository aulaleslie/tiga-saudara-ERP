## ADDED Requirements

### Requirement: Checkout cash events SHALL represent physical drawer movement
For every posted checkout containing a cash payment, the system SHALL record the actual cash amount tendered by the customer as a cash-in event and SHALL record change returned to the customer exactly once as a separate cash-out event. The net checkout contribution to expected drawer cash MUST equal cash tendered minus change returned.

#### Scenario: Cash-only checkout with change
- **WHEN** a customer tenders Rp800,000 cash for a Rp780,000 checkout and receives Rp20,000 change
- **THEN** the session ledger SHALL contain a Rp800,000 cash-in event
- **AND** the session ledger SHALL contain a Rp20,000 change-out event
- **AND** the checkout SHALL increase expected drawer cash by Rp780,000

#### Scenario: Cash-only checkout without change
- **WHEN** a customer tenders exactly Rp780,000 cash for a Rp780,000 checkout
- **THEN** the session ledger SHALL contain a Rp780,000 cash-in event
- **AND** the session ledger SHALL NOT contain a change-out event for that checkout
- **AND** the checkout SHALL increase expected drawer cash by Rp780,000

#### Scenario: Mixed-payment checkout with cash change
- **WHEN** a checkout is paid using cash and one or more non-cash methods and the cash tender creates customer change
- **THEN** the session ledger SHALL record only the actual cash component as cash inflow
- **AND** the session ledger SHALL record the full customer change exactly once as cash outflow
- **AND** non-cash payment amounts SHALL NOT increase expected drawer cash

#### Scenario: Non-cash-only checkout
- **WHEN** a checkout is paid entirely through non-cash methods
- **THEN** the checkout SHALL create neither a cash-sale-in event nor a change-out event
- **AND** the checkout SHALL NOT change expected drawer cash

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

### Requirement: Closed-session cash history SHALL remain immutable
Deploying the corrected cash-event behavior MUST NOT silently rewrite cash events or expected-cash audit values for sessions that were already closed or finalized. Historical discrepancies MAY be surfaced through a read-only diagnostic.

#### Scenario: Existing closed session contains legacy change accounting
- **WHEN** the corrected behavior is deployed and a closed or finalized session contains legacy cash events
- **THEN** the system SHALL preserve those event rows and their recorded audit history
- **AND** deployment SHALL NOT automatically replace or rebalance the session's stored events

#### Scenario: Diagnostic identifies legacy double deduction
- **WHEN** an authorized operator runs the optional historical diagnostic
- **THEN** it SHALL report affected sessions and the calculated discrepancy without mutating session or cash-event data

