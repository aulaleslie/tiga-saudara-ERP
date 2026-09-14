## ADDED Requirements

### Requirement: Each approved return batch has one receipt lineage
The system SHALL bind one independently revised `RETURN_RECEIPT` lineage to exactly one approved `RETURN_DISPATCH` batch and SHALL permit different batches on the same transfer to be received independently and concurrently.

#### Scenario: Create receipt for an approved batch
- **WHEN** an authorized original-location recipient starts receipt for an approved return-dispatch batch with active reservations
- **THEN** revision `1` is created empty with the exact source movement and return-batch lineage

#### Scenario: Create a second receipt for the same batch
- **WHEN** an open or approved receipt already exists for that source batch
- **THEN** another independent receipt lineage cannot be created

#### Scenario: Receive different batches concurrently
- **WHEN** several approved return-dispatch batches remain in transit
- **THEN** each may have its own open or approved receipt without blocking the others

### Requirement: Return-receipt preparation starts completely blind
Every return-receipt draft SHALL start with no copied lines or serials, and its preparation projection MUST omit all source-manifest products, quantities, serials, allocations, reservations, custody, stock, and differences regardless of stock-visibility permission.

#### Scenario: Users with different visibility prepare the same receipt
- **WHEN** a blind user and stock-visible user open the same receipt draft
- **THEN** both receive only permitted document context and their entered observations

#### Scenario: Recipient records an unexpected observation
- **WHEN** a recipient scans a product or serial absent from the hidden source manifest
- **THEN** the draft records the physical observation without identifying it as unexpected

#### Scenario: Recipient confirms an empty count
- **WHEN** no observations exist and the recipient explicitly confirms empty
- **THEN** the confirmer and timestamp are retained and the empty draft may be submitted

### Requirement: Return receipt requires exact source-batch equality
Approval MUST require the receipt's canonical products, exact integer base quantities, condition, and serialized identities to equal its one approved return-dispatch source manifest.

#### Scenario: Exact receipt approves
- **WHEN** all observed products, quantities, condition, and serial sets exactly equal the source batch
- **THEN** the receipt may proceed to locked inventory and fulfillment execution

#### Scenario: Partial batch is received
- **WHEN** the receipt omits any quantity or serial from its source batch
- **THEN** approval is rejected without partially accepting the batch

#### Scenario: Several batches are combined
- **WHEN** observations include goods belonging to another return-dispatch batch
- **THEN** approval fails against the selected source manifest

#### Scenario: Substitute serial is received
- **WHEN** a serialized observation differs from the serial approved in the source return dispatch
- **THEN** approval fails even when it is the same product and condition

### Requirement: Receipt rejection and correction preserve blindness
Submitted receipts SHALL remain immutable, rejection SHALL require an audited reason, and correction SHALL create the next revision in the same source-batch lineage with no copied observations or hidden expectations.

#### Scenario: Correct rejected receipt
- **WHEN** an authorized recipient starts correction of a rejected receipt
- **THEN** a new empty draft supersedes it while the rejected attempt remains unchanged

### Requirement: Receipt actions are idempotent
Submission and approval MUST be idempotent within movement, revision, and action scope and MUST NOT duplicate inventory, tax snapshots, serial history, custody closure, reservation closure, obligation fulfillment, or header history.

#### Scenario: Approval is replayed
- **WHEN** the same completed receipt approval is retried with its idempotency identity
- **THEN** the committed result is returned without repeating any effect
