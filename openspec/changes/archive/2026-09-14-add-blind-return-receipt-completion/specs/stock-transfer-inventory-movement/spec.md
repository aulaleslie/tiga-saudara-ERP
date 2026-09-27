## ADDED Requirements

### Requirement: Approved return receipt restores origin inventory atomically
Approval MUST lock authoritative source, receipt, product, origin stock, allocation, obligation, reservation, tax, serial, and custody state before adding the exact source-batch quantity to origin-classified inventory and recording immutable transaction and before/after provenance.

#### Scenario: Good stock returns to non-PKP origin
- **WHEN** an exact good-condition receipt approves for a non-PKP origin
- **THEN** its full quantity is added to good non-tax stock

#### Scenario: Broken stock returns to PKP origin
- **WHEN** an exact broken-condition receipt approves for a PKP origin
- **THEN** its full quantity is added to broken taxed stock under the processing-time tax snapshot

#### Scenario: Approval fails after inventory begins
- **WHEN** any later custody, reservation, obligation, history, or header invariant fails
- **THEN** all origin inventory and transaction effects roll back

### Requirement: Exact returned serials close custody at origin
Approved receipt SHALL require every serialized observation to identify the exact live serial and active claim from its source return dispatch, move it to the original location, apply origin tax classification, close movement custody, remove the active claim, and record immutable before/after history.

#### Scenario: Exact in-transit serial returns
- **WHEN** serial identity, product, condition, live location, source movement serial, and active claim all match
- **THEN** approval moves the serial to origin and makes it available under its new classification

#### Scenario: Claim is absent or mismatched
- **WHEN** the active claim does not identify the source return-dispatch movement and serial row
- **THEN** approval fails without moving any serial or inventory

#### Scenario: Serial text or product drifts
- **WHEN** locked live serial identity or product differs from the immutable source manifest
- **THEN** approval fails atomically

### Requirement: Return-receipt transaction provenance is exact
Each approved line SHALL store a canonical origin inventory transaction reference whose type, setting, location, product, quantity, classification, actor, and source-return lineage are validated and auditable.

#### Scenario: Approval replay references existing transaction
- **WHEN** approval is replayed with the completed action identity
- **THEN** no second transaction or inventory increment is created
