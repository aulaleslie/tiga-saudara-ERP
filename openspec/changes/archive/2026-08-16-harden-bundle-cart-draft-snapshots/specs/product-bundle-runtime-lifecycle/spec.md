## ADDED Requirements

### Requirement: Current operational classifications SHALL remain authoritative safety gates
For an open or persisted bundle transaction, current product `stock_managed` and serial-required classifications SHALL govern operational stock, serial, ownership, split-planning, and posting validation. Classification drift SHALL NOT replace captured component identity, quantity, informational allocation, or parent commercial price, and lifecycle acknowledgement SHALL NOT bypass an operational failure.

#### Scenario: Component becomes stock managed after capture
- **WHEN** a captured bundle component is currently classified as stock managed even though its captured metadata recorded it as stockless
- **THEN** the next operational gate SHALL require current stock fulfillment for the captured component quantity
- **AND** insufficient stock SHALL block the operation after any lifecycle acknowledgement

#### Scenario: Component becomes stockless after capture
- **WHEN** a captured bundle component is currently classified as stockless even though its captured metadata recorded it as stock managed
- **THEN** stock resolution and posting SHALL use the current stockless classification
- **AND** the transaction SHALL retain the captured component and commercial allocation

#### Scenario: Parent serial requirement changes after capture
- **WHEN** the current parent product classification requires serials for an existing cart or draft
- **THEN** the applicable POS or Sales operational gate SHALL require valid serial assignment according to the existing parent-line workflow
- **AND** stale captured metadata SHALL NOT bypass that requirement

### Requirement: Unsupported POS bundle-component serial demand SHALL block explicitly
Until POS provides component-level serial assignment, a current serial-required classification on a stock-managed bundle component SHALL be treated as an unsupported operational state. The system SHALL identify the affected component and block new finalization rather than submitting an empty serial list or treating acknowledgement as authorization.

#### Scenario: Bundle component currently requires serials
- **WHEN** POS finalization evaluates a captured stock-managed bundle component whose current product classification requires serial numbers
- **THEN** finalization SHALL fail with a dedicated bundle-component-serial unsupported validation
- **AND** the response SHALL identify the affected bundle line and component

#### Scenario: Lifecycle warning is acknowledged for serial-required component
- **WHEN** a user acknowledges bundle drift but the captured bundle contains a currently serial-required component without a supported assignment path
- **THEN** the operational validation SHALL still block finalization
- **AND** no checkout, Sale, dispatch, payment, or stock mutation SHALL occur

#### Scenario: Component serial UI remains out of scope
- **WHEN** this hardening change is deployed
- **THEN** the POS bundle detail UI SHALL NOT claim that component serial assignment is available
- **AND** component-level serial entry and persistence SHALL remain deferred to the dedicated component-serial change
