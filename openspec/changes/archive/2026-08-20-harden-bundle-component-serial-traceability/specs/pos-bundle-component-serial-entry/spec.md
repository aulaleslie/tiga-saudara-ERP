## ADDED Requirements

### Requirement: Serial assignment SHALL be unique across every cart position
The POS system SHALL treat parent-line, standalone-line, and bundle-component serial assignments as one cart-wide uniqueness set. Browser feedback SHALL inspect component assignments, and the authoritative server operation MUST reject a duplicate even when browser validation is bypassed or stale.

#### Scenario: Same serialized SKU is standalone and bundled
- **WHEN** Product A is present as a standalone serial-required line and as a serial-required component of Product B
- **THEN** POS SHALL require a distinct eligible Product A serial for each position
- **AND** it MUST reject assigning the same serial to both positions

#### Scenario: Duplicate component serial is detected before submission
- **WHEN** a cashier enters a serial already assigned to any parent or component position in the current cart
- **THEN** the POS interface SHALL identify the duplicate without treating it as a valid component assignment
- **AND** the server MUST independently reject the duplicate assignment

### Requirement: Mixed serialized bundle positions SHALL be independently fulfilled
For a bundle whose parent and one or more components currently require serials, POS SHALL calculate the required count for each position from the cart quantity and component quantity-per-bundle and SHALL require exact, mutually unique assignments before checkout.

#### Scenario: Parent and component both require serials
- **WHEN** a bundled row has a serial-required parent and a serial-required component
- **THEN** checkout SHALL require the exact parent serial count and exact component serial count
- **AND** no serial may satisfy more than one position

#### Scenario: Bundle contains multiple serial-required components
- **WHEN** a bundle contains multiple serial-required component rows
- **THEN** POS SHALL maintain and validate an independent assignment set for each component row

### Requirement: Quantity and removal changes SHALL release or invalidate assignments safely
POS SHALL recompute component serial requirements when bundle quantity changes and SHALL remove all component serial assignments when their bundle line is removed. Checkout MUST reject underfilled or overfilled assignments.

#### Scenario: Bundle quantity changes
- **WHEN** a cashier changes a bundled row quantity after assigning component serials
- **THEN** POS SHALL display the new required count for every serial-required position
- **AND** checkout MUST remain blocked until each assignment count exactly matches its requirement

#### Scenario: Bundle line is removed
- **WHEN** a bundled row with assigned component serials is removed from the cart
- **THEN** those assignments SHALL no longer participate in cart duplicate checks or serial reservation decisions

