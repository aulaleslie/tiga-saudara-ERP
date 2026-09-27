## ADDED Requirements

### Requirement: Forward-dispatch preparation has blind and privileged projections
The system SHALL project forward-dispatch preparation according to `stockTransfers.view-system-stock`, and movement action permissions MUST NOT imply access to protected request, stock, allocation, serial-expectation, or difference information.

#### Scenario: Blind dispatcher prepares a count
- **WHEN** an authorized dispatcher lacks stock visibility
- **THEN** the dispatcher sees approved product identities, condition, their own entered quantities and serials, and confirmation state but receives no requested quantities, expected serials, stock quantities, buckets, allocation, remaining/maximum values, or differences

#### Scenario: Privileged dispatcher prepares a count
- **WHEN** an otherwise-authorized dispatcher has stock visibility
- **THEN** the preparation projection may include approved request quantities and serials, authoritative origin stock, buckets, and allocation information

#### Scenario: Blind payload is inspected
- **WHEN** rendered HTML, Livewire state, snapshots, events, validation data, and session payloads are inspected for a blind dispatcher
- **THEN** protected keys and distinctive protected values are absent rather than hidden, masked, hashed, or represented by placeholders

### Requirement: Forward-dispatch approval comparison respects stock visibility
An actor with dispatch-approval permission but without stock visibility SHALL receive only a neutral server-authoritative match or failure result, while an otherwise-authorized approver with stock visibility MAY receive exact expected-versus-counted and allocation detail.

#### Scenario: Blind approver reviews exact count
- **WHEN** server comparison and locked stock validation pass for an approver without stock visibility
- **THEN** the approver may approve without receiving exact request, count, stock, bucket, allocation, serial-expectation, or difference values

#### Scenario: Blind approver reviews mismatch
- **WHEN** comparison or authoritative fulfillment fails for an approver without stock visibility
- **THEN** the browser receives neutral non-quantitative guidance and the movement remains pending without protected detail

#### Scenario: Privileged approver reviews mismatch
- **WHEN** an approver also holds stock visibility
- **THEN** the comparison may show exact request, physical count, stock, allocation, expected and counted serials, and line-level differences needed for review

#### Scenario: Crafted approval payload supplies protected data
- **WHEN** any approver injects expected quantities, stock values, bucket allocation, serial provenance, or comparison results
- **THEN** the system ignores the client values and derives the comparison and allocation from locked authoritative server data

