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
A transfer with full-return obligations SHALL enter `AWAITING_RETURN` after exact forward-receipt approval, MAY reserve outstanding quantities across multiple concurrent approved partial return-dispatch batches, and SHALL not complete until later return-receipt approval fulfills every obligation and no unresolved batch remains.

#### Scenario: Mandatory forward receipt approves
- **WHEN** all forward-receipt checks pass for a route requiring return
- **THEN** destination stock is applied, obligations become outstanding, and the transfer becomes `AWAITING_RETURN`

#### Scenario: Partial return dispatch approves
- **WHEN** a return-dispatch batch reserves less than the full outstanding obligation
- **THEN** the reserved quantity becomes in transit while the obligation remains unfulfilled

#### Scenario: Several batches are in transit
- **WHEN** multiple approved return-dispatch batches reserve different portions of the same or different obligations
- **THEN** every reservation remains independently linked and their aggregate does not exceed the required quantity

#### Scenario: Exact return receipt approves
- **WHEN** a receipt exactly matches one source batch
- **THEN** that batch's reservations close and its quantities become returned

#### Scenario: No-return receipt approves
- **WHEN** all forward-receipt checks pass for a route not requiring return
- **THEN** no obligation is created and the transfer becomes `COMPLETED`

### Requirement: Delivery 7 obligations remain operationally dormant
The system SHALL reserve obligation capacity only through approved return dispatch and SHALL increment `returned_quantity` only through approval of the exact linked return receipt.

#### Scenario: Return dispatch approves
- **WHEN** an eligible partial return-dispatch batch is approved
- **THEN** active reservations are created without incrementing returned quantity

#### Scenario: Exact linked receipt approves
- **WHEN** a return receipt exactly matches its approved source dispatch and passes all locked validation
- **THEN** its active reservations close and returned quantities increase exactly once

#### Scenario: Receipt fails or is rejected
- **WHEN** receipt preparation, submission, comparison, rejection, cancellation, correction, or approval fails
- **THEN** reservations remain active and returned quantities remain unchanged

### Requirement: Receipt fulfillment is capacity-safe and atomic
Receipt approval MUST lock each source reservation and obligation, MUST increment returned quantity by exactly the reserved quantity without exceeding required quantity, and MUST commit fulfillment with inventory, custody, movement, and header effects in one transaction.

#### Scenario: One of several batches is received
- **WHEN** one exact receipt approves while other batches remain active
- **THEN** only its reservations close and only its quantities are credited as returned

#### Scenario: Final batch is received
- **WHEN** the receipt closes the final active reservation and every obligation reaches its required quantity
- **THEN** every obligation is fulfilled and the transfer becomes `COMPLETED`

#### Scenario: Fulfillment would exceed requirement
- **WHEN** stored reservation or obligation state would make returned quantity exceed required quantity
- **THEN** approval fails atomically without closing the reservation

### Requirement: Concurrent reservations cannot overcommit an obligation
Return-dispatch approval MUST lock authoritative obligation and active-reservation state and MUST enforce that returned quantity plus active in-transit quantity plus the newly approving quantity does not exceed required quantity.

#### Scenario: Concurrent batches fit within capacity
- **WHEN** two independently approved batches together remain within an obligation's available quantity
- **THEN** both may persist as distinct active reservations

#### Scenario: Concurrent batches exceed capacity
- **WHEN** competing approvals would make aggregate returned and active in-transit quantity exceed the requirement
- **THEN** locking permits only capacity-valid approvals and rejects the excess batch atomically

#### Scenario: Drafts compete for the final quantity
- **WHEN** several drafts contain the same final available quantity
- **THEN** drafts hold no capacity and only authoritative approval creates a reservation
