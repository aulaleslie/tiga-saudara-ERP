## ADDED Requirements

### Requirement: Bundle-component serials SHALL be revalidated from locked current state
Before creating inventory effects, checkout SHALL lock or otherwise use the existing authoritative concurrency guard for each assigned bundle-component serial and revalidate product identity, active availability, current owner/location access, tax provenance, reservation state, and absence of a completed dispatch. Earlier cart validation MUST NOT authorize a stale assignment.

#### Scenario: Component serial moves after cart assignment
- **WHEN** an assigned component serial is moved, sold, returned, reserved, or otherwise becomes unavailable before finalization
- **THEN** finalization SHALL fail atomically with an error identifying the bundle component and serial
- **AND** no Sale, dispatch, stock, serial-state, movement-history, payment, or checkout posting effect SHALL remain

#### Scenario: Component serial remains eligible
- **WHEN** every assigned component serial remains eligible at its authoritative source immediately before posting
- **THEN** checkout SHALL use that source setting, location, and provenance for the component dispatch and stock movement

### Requirement: Component serial posting SHALL reconcile current state and movement history exactly once
Successful checkout SHALL link every fulfilled component serial to its component DispatchDetail, transition its current serial record once, and create the corresponding immutable serial movement/history record once within the same atomic posting boundary.

#### Scenario: Split-owner component serial is posted
- **WHEN** a serialized bundle component is fulfilled by a source owner or location different from the POS owner
- **THEN** its DispatchDetail, current serial state, stock effect, and movement/history record SHALL use the actual component source
- **AND** all records SHALL reference the same fulfilled serial and originating checkout lineage

#### Scenario: Later owner group fails
- **WHEN** split posting updates a component serial but a later owner group fails
- **THEN** the component stock effect, serial transition, dispatch link, and movement/history record SHALL all roll back

#### Scenario: Matching finalize request is replayed
- **WHEN** an already-posted checkout is finalized again with the matching idempotency payload
- **THEN** POS SHALL return the stored result
- **AND** SHALL NOT create another dispatch, serial transition, stock effect, or movement/history record

