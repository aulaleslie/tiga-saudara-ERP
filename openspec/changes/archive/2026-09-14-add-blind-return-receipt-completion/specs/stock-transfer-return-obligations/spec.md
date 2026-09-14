## MODIFIED Requirements

### Requirement: Mandatory routes await later return execution
A transfer with full-return obligations SHALL enter `AWAITING_RETURN` after exact forward-receipt approval, MAY reserve outstanding quantities across multiple concurrent approved partial return-dispatch batches, and SHALL become `COMPLETED` only after exact return-receipt approvals fulfill every obligation and no unresolved active batch remains.

#### Scenario: Mandatory forward receipt approves
- **WHEN** all forward-receipt checks pass for a route requiring return
- **THEN** destination stock is applied, obligations become outstanding, and the transfer becomes `AWAITING_RETURN`

#### Scenario: Partial return dispatch approves
- **WHEN** a return-dispatch batch reserves less than the full outstanding obligation
- **THEN** the reserved quantity becomes in transit while the obligation remains unfulfilled

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

## ADDED Requirements

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
