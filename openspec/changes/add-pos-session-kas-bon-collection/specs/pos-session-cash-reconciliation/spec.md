## ADDED Requirements

### Requirement: Kas Bon collection cash belongs to the receiving session
Cash received through POS Kas Bon collection SHALL be recorded as collection cash inflow in the receiving session, with change recorded exactly once as outflow. Net drawer movement SHALL equal cash tender minus change. Non-cash SHALL not increase expected drawer cash. The originating checkout and session SHALL remain unchanged.

#### Scenario: Cash collection with change
- **WHEN** the current session collects Rp50,000 of an older Kas Bon using Rp100,000 cash tender and Rp50,000 change
- **THEN** the receiving ledger records Rp100,000 collection inflow and Rp50,000 change outflow
- **AND** expected drawer cash increases by Rp50,000
- **AND** the original session remains unchanged

#### Scenario: Transfer-only collection
- **WHEN** a collection is paid entirely by transfer
- **THEN** it is associated with the receiving session without any cash inflow or change outflow

### Requirement: Session reporting distinguishes collections from sales
Session summaries and reconciliation SHALL separately display Kas Bon cash and non-cash collections. Pickup limits, cash thresholds, closing expected cash, and variance SHALL include net collection cash through the cash-event ledger. Collection SHALL not inflate new-sales totals or count multiple times because payment is split across generated Sales.

#### Scenario: Mixed collection and pickup
- **WHEN** opening float is Rp200,000 and a Rp100,000 collection comprises Rp60,000 cash and Rp40,000 transfer
- **THEN** expected cash and available pickup are Rp260,000 before other movements
- **AND** the session reports Rp60,000 cash collections and Rp40,000 non-cash collections separately from new sales

#### Scenario: Split-owner collection
- **WHEN** one collection creates payments across multiple generated Sales
- **THEN** session reporting counts the collection and its physical cash movement once

### Requirement: Sale payment invalidation preserves recorded collection cash
Invalidating a Sale payment SHALL NOT delete or reverse the receiving session's historical collection cash movement or receipt. Debt projection SHALL follow canonical active settlement independently. Physical cash corrections SHALL remain the responsibility of a separate workflow outside POS collection.

#### Scenario: Invalidation during the receiving session
- **WHEN** Sales invalidates a collected payment while the receiving session is still open and no money has been returned
- **THEN** live debt reflects invalidation and expected drawer cash retains the money physically received

#### Scenario: Invalidation after session closure
- **WHEN** Sales invalidates a collected payment after the receiving session is closed or finalized
- **THEN** the closed session's collection cash events and finalized history remain unchanged
