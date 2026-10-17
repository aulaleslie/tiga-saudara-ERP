## MODIFIED Requirements

### Requirement: Privileged users can edit fulfilled document monetary values in place
The system SHALL allow a user with ordinary edit authority plus `purchases.received.monetary.edit` to edit a Purchase in `RECEIVED` or `RECEIVED PARTIALLY` status, and a user with ordinary edit authority plus `sales.dispatched.monetary.edit` to edit a Sale in `DISPATCHED`, `DISPATCHED PARTIALLY`, or `RETURNED PARTIALLY` status, within the active setting. The edit SHALL persist supported monetary values directly to the existing document header and detail rows.

#### Scenario: Authorized user changes a received purchase line price
- **WHEN** an authorized user saves a monetary edit for an eligible received Purchase
- **THEN** the system SHALL update the allowed monetary values on the existing Purchase and PurchaseDetail records
- **AND** the system SHALL retain every existing PurchaseDetail primary key

#### Scenario: Authorized user changes a dispatched sale monetary value
- **WHEN** an authorized user saves a monetary edit for an eligible dispatched Sale
- **THEN** the system SHALL update the allowed monetary values on the existing Sale and SaleDetails records
- **AND** the system SHALL retain every existing SaleDetails primary key

#### Scenario: Unauthorized user is denied
- **WHEN** a user lacks either ordinary edit authority or the applicable post-fulfillment monetary permission and attempts to open or save an eligible document
- **THEN** the system SHALL deny the action at the backend
- **AND** the system SHALL not change the document

#### Scenario: Authorized user edits a partially returned sale
- **WHEN** an authorized user opens or saves a monetary edit for a Sale in RETURNED PARTIALLY status
- **THEN** ordinary and existing global monetary-edit entry points SHALL support the remaining sale under their existing authorization and setting rules
- **AND** fully returned Sales SHALL not gain edit eligibility from this change

## ADDED Requirements

### Requirement: Partially returned monetary edits preserve completed return evidence
A monetary edit after a partial return SHALL apply supported monetary adjustments only to the remaining sale and reconcile current totals, retained payments and debt using existing payment rules. It MUST preserve completed refund amounts and settlement evidence, return history, detail identities, quantities, serials, dispatches, stock and cost snapshots. Fully returned zero-quantity audit rows MUST NOT gain charges.

#### Scenario: Remaining quantity is repriced
- **GIVEN** a Sale originally contains 5 pcs and a completed cash return has reduced it to 4 pcs
- **WHEN** an authorized user changes its remaining unit price
- **THEN** the new line value SHALL use 4 pcs
- **AND** the completed refund and original return evidence SHALL remain unchanged
- **AND** current totals and debt SHALL reconcile under existing payment rules

#### Scenario: Protected execution inputs are tampered with
- **WHEN** a monetary edit attempts to change quantity, serials, product identity or protected row membership
- **THEN** the backend SHALL reject the edit without changing the Sale or its execution history

#### Scenario: Fully returned audit line stays uncharged
- **WHEN** another remaining item is monetarily edited on a partially returned Sale
- **THEN** a fully returned zero-quantity audit line SHALL remain preserved and uncharged
