# stock-transfer-return-obligations Specification

## Purpose
Full-product, condition-scoped return obligations created atomically from mandatory-route forward receipt, held dormant pending later return-dispatch and return-receipt deliveries.

## Requirements

### Requirement: Mandatory receipt creates full-product obligations
Approval of an exact forward receipt whose route snapshot requires return SHALL create one obligation per received product and transfer condition for the full received base quantity, regardless of source or destination tax bucket.

#### Scenario: PKP to non-PKP receipt
- **WHEN** ten exact units are approved at the non-PKP destination
- **THEN** all ten units become obligated even though destination inventory is classified as non-tax

#### Scenario: Mixed source provenance
- **WHEN** an approved dispatch contains both tax and non-tax source quantities
- **THEN** the obligation equals the full received product quantity rather than either source bucket

#### Scenario: Broken transfer
- **WHEN** an exact broken-stock receipt is approved for a mandatory route
- **THEN** the full quantity is obligated under the broken condition without creating a good-stock obligation

### Requirement: Obligation creation is atomic and idempotent
Receipt inventory application, serial reclassification, obligation creation, movement history, and transfer status projection MUST commit once in one transaction.

#### Scenario: Obligation persistence fails
- **WHEN** any obligation row cannot be created after receipt application begins
- **THEN** all destination inventory, serial, custody, claim, transaction, history, movement, obligation, and header effects roll back

#### Scenario: Receipt approval is replayed
- **WHEN** an already committed receipt approval is repeated with the same idempotency identity
- **THEN** no obligation quantity or related inventory effect is duplicated

### Requirement: Mandatory routes await later return execution
A transfer with full-return obligations SHALL enter `AWAITING_RETURN` after exact forward-receipt approval and SHALL not complete until later return-dispatch and return-receipt capabilities fulfill every obligation.

#### Scenario: Mandatory forward receipt approves
- **WHEN** all forward-receipt checks pass for a route requiring return
- **THEN** destination stock is applied, obligations become outstanding, and the transfer becomes `AWAITING_RETURN`

#### Scenario: No-return receipt approves
- **WHEN** all forward-receipt checks pass for a route not requiring return
- **THEN** no obligation is created and the transfer becomes `COMPLETED`

### Requirement: Delivery 7 obligations remain operationally dormant
The system SHALL retain and expose obligations only through authorized domain/audit projections in this change and MUST NOT permit return dispatch, fulfillment, or completion mutations until their dedicated deliveries are activated.

#### Scenario: User attempts return execution before Delivery 8
- **WHEN** an actor attempts to create or approve a return movement through a production-facing surface
- **THEN** no operational route is available and no stock or obligation state changes
