# Spec Delta

## ADDED Requirements

### Requirement: Consignment transfer posting preserves supplier receipt provenance
For consignment-to-consignment routes, transfer execution SHALL atomically preserve receipt-lot quantities or exact serial provenance alongside its existing inventory movements. Provenance SHALL retain original supplier and billing business, actual custody businesses, condition/tax evidence, and exact dispatch/receipt links without changing ordinary transfer quantity, tax, or authorization rules.

#### Scenario: Non-serialized consignment dispatch spans receipt lots
- **WHEN** a transfer dispatch selects six units from one receipt and two from another
- **THEN** its eight-unit stock deduction and both receipt-backed transit allocations SHALL commit together
- **AND** no supplier payable, sale, or POS mutation SHALL be created

#### Scenario: Consignment receipt completes
- **WHEN** a destination receives a verified consignment dispatch
- **THEN** its actual received quantities SHALL move from that dispatch's transit allocations to destination custody exactly once with original supplier responsibility preserved

#### Scenario: Provenance posting fails on a later allocation
- **WHEN** required receipt, serial, custody, or audit evidence cannot be persisted
- **THEN** every transfer inventory, transit, claim, and provenance effect SHALL roll back

### Requirement: Consignment cancellations and returns compensate provenance
A consignment transfer cancellation or return SHALL compensate only the exact original receipt-backed quantities or serials still eligible for that action. It SHALL preserve completed sale, supplier billing, and acquisition dependencies and SHALL not recreate custody already consumed or owned through another operation.

#### Scenario: Cancellation restores its source
- **WHEN** an eligible consignment dispatch is cancelled before receipt
- **THEN** its transit quantity and matching claims SHALL close and its exact source receipt-lot custody SHALL be restored once

#### Scenario: Return follows partial onward consumption
- **WHEN** some received consignment quantity was already sold, transferred onward, reserved, or bought out
- **THEN** transfer return SHALL be limited to genuinely available receipt-backed quantity and SHALL retain the correct original lot links

### Requirement: Consignment classification boundaries remain enforced
Ordinary stock transfers SHALL reject routes between consignment and standard locations in either direction. This change SHALL not relax that rule; movement into standard owned stock SHALL occur only through the dedicated approved buyout workflow. Non-consignment transfer preparation and posting SHALL retain existing behavior.

#### Scenario: Ordinary transfer crosses classification
- **WHEN** an ordinary transfer attempts consignment-to-standard or standard-to-consignment movement
- **THEN** it SHALL be rejected without stock or provenance mutation

#### Scenario: Both locations are consignment
- **WHEN** an otherwise authorized transfer connects consignment locations of the same or different businesses
- **THEN** it SHALL follow existing route/tax rules plus exact consignment receipt provenance checks

### Requirement: Legacy workflow consignment dispatch is refused
Transfer workflows that cannot post receipt provenance (versions 1 and 2) SHALL refuse a new dispatch or return dispatch whose origin is a consignment location, before any stock, serial, claim, or transaction effect, with an actionable message directing the operator to a version 3 transfer and receipt-lot confirmation. Receiving of documents already dispatched SHALL remain available. Ordinary non-consignment version 1 and version 2 dispatches SHALL keep their existing behavior.

#### Scenario: Legacy consignment dispatch is attempted
- **WHEN** a version 1 or version 2 transfer dispatch or return dispatch originates at a consignment location
- **THEN** it SHALL be rejected without inventory or provenance mutation
- **AND** the message SHALL direct the operator to cancel or archive it and create a version 3 transfer with confirmed receipt lots

#### Scenario: Ordinary legacy transfer is dispatched
- **WHEN** a version 1 or version 2 transfer between non-consignment locations is dispatched
- **THEN** its stock, serial, and transaction effects SHALL be unchanged and no provenance SHALL be recorded

#### Scenario: Legacy consignment evidence is reconciled
- **WHEN** historical reconstruction encounters a version 1 or version 2 movement from a consignment origin
- **THEN** it SHALL report an unsupported-version blocker rather than inferring version 3 allocation evidence

