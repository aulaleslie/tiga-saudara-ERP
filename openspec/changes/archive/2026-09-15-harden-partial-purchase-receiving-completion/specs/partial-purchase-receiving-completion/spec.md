## MODIFIED Requirements

### Requirement: Authorized users can complete a supplier-shortfall purchase
The system SHALL allow only users with `purchases.receive.complete_shortfall` to complete a purchase in the active setting when it is unarchived, has status `RECEIVED PARTIALLY`, has at least one positive cumulative received quantity from `APPROVED` receiving-note details, has an outstanding ordered quantity, and has no pending receiving notes. An approved receiving-note header containing no positive detail quantity SHALL NOT make a purchase eligible.

#### Scenario: Authorized user completes an eligible partial purchase
- **WHEN** an authorized user submits a valid shortfall completion for an eligible purchase with at least one positive approved receiving-detail quantity
- **THEN** the system SHALL complete the purchase atomically
- **AND** the purchase status SHALL become `RECEIVED`

#### Scenario: User without completion permission is denied
- **WHEN** a user without `purchases.receive.complete_shortfall` requests the completion preview or submits completion
- **THEN** the system SHALL deny the request
- **AND** the purchase, details, payments, and receiving records SHALL remain unchanged

#### Scenario: Ineligible purchase cannot be completed
- **WHEN** a user attempts completion for an archived, foreign-setting, non-partial, no-positive-approved-receipt, fully fulfilled, or pending-receipt purchase
- **THEN** the system SHALL reject the operation
- **AND** it SHALL not change the purchase status or financial data

#### Scenario: Approved receival with only zero quantities is insufficient
- **WHEN** a partial purchase has an `APPROVED` receiving-note header but every detail in all approved receiving notes has cumulative received quantity zero
- **THEN** the system SHALL reject the completion preview and submission as ineligible
- **AND** it SHALL leave the purchase and receiving records unchanged

### Requirement: Completion normalizes the purchase from approved receipt quantities
The system SHALL calculate cumulative receipt quantities using only `APPROVED` received notes. It SHALL retain each purchase-detail row with a positive cumulative approved quantity and update its quantity in place to that quantity. It SHALL remove each purchase-detail row with zero cumulative approved quantity, including a row referenced by zero-quantity receiving-note details, and SHALL preserve the original line and removal outcome in the immutable completion audit.

#### Scenario: One product is short-delivered
- **WHEN** a purchase detail ordered quantity is 10 and its cumulative approved receipt quantity is 5 at successful completion
- **THEN** the existing purchase-detail row SHALL remain linked to its positive receiving history
- **AND** its final ordered quantity SHALL be 5

#### Scenario: One product is never delivered and has no receiving detail
- **WHEN** a purchase detail ordered quantity is 10 has cumulative approved received quantity zero and no receiving-detail history at successful completion
- **THEN** the system SHALL remove that purchase-detail row
- **AND** it SHALL retain the line's original and removal result in the completion audit

#### Scenario: Approved receival contains a zero-quantity product row
- **WHEN** an approved receival has a positive received quantity for product A and a zero received quantity for product B
- **AND** product B has zero cumulative approved received quantity at successful completion
- **THEN** the system SHALL retain and normalize product A's purchase-detail row
- **AND** it SHALL remove product B's purchase-detail row despite its zero-quantity receiving-detail history
- **AND** the persisted retained and removed line sets SHALL match the completion preview and audit

#### Scenario: Rejected and pending notes do not count as received
- **WHEN** a purchase has rejected receiving notes and no pending notes
- **THEN** rejected note quantities SHALL not contribute to final quantities
- **AND** when a purchase has a pending receiving note completion SHALL be blocked

#### Scenario: Zero-approved line cannot be removed safely
- **WHEN** deletion of a zero-approved purchase detail is prevented by unexpected dependent material evidence
- **THEN** the system SHALL fail the completion atomically
- **AND** it SHALL leave the purchase, details, payments, receiving records, and audit records unchanged
