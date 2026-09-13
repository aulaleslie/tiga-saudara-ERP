## MODIFIED Requirements

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

#### Scenario: No-return receipt approves
- **WHEN** all forward-receipt checks pass for a route not requiring return
- **THEN** no obligation is created and the transfer becomes `COMPLETED`

### Requirement: Delivery 7 obligations remain operationally dormant
The system SHALL permit authorized return-dispatch movements to reserve outstanding obligation capacity and SHALL keep `returned_quantity` unchanged until Delivery 9 independently approves the exact linked return receipt.

#### Scenario: Return dispatch approves
- **WHEN** an eligible partial return-dispatch batch is approved
- **THEN** an active reservation is linked to each approved line and obligation without incrementing returned quantity

#### Scenario: Return dispatch fails or is rejected
- **WHEN** preparation, submission, comparison, approval, rejection, cancellation, or correction does not produce an approved manifest
- **THEN** no obligation capacity is reserved and no returned quantity changes

#### Scenario: User attempts return receipt before Delivery 9
- **WHEN** any actor attempts to receive an approved return-dispatch batch through a production-facing surface
- **THEN** no operational route is available and no reservation, obligation, origin inventory, custody, or transfer-completion state changes

## ADDED Requirements

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
