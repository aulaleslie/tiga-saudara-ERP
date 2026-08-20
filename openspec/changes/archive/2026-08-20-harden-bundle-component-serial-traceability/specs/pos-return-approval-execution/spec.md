## Purpose
POS Returns maintain accurate serial lineage and prevent serial conflicts in bundle component returns by resolving components through persisted transaction history rather than the live bundle definition.

## ADDED Requirements

### Requirement: POS returns SHALL preserve bundle-component serial lineage
Return snapshot, submission, approval, receiving, and replacement execution SHALL resolve a serialized bundle component through its persisted POS transaction line, owner-specific SaleDetail, component DispatchDetail, and serial record rather than through the current live bundle definition.

#### Scenario: Serialized bundle component is returned
- **WHEN** a user returns a serialized component from a completed bundled checkout
- **THEN** the POS Return and linked Sales Return detail SHALL reference the originally fulfilled component serial and dispatch lineage
- **AND** approval and receiving SHALL create the corresponding return serial movement/history and resulting current state exactly once

#### Scenario: Bundle changed after sale
- **WHEN** the live bundle is edited, disabled, or deleted after the serialized component was sold
- **THEN** return eligibility and execution SHALL continue from persisted historical composition and serial lineage

### Requirement: One serial SHALL NOT be consumed by overlapping active returns
The POS Return workflow SHALL count a serialized bundle component as unavailable when the same fulfilled serial is already consumed by another return that participates in cumulative eligibility. This rule MUST be enforced authoritatively during submission or update under the existing atomic concurrency boundary.

#### Scenario: Same serial is submitted in two active returns
- **WHEN** two return attempts target the same originally fulfilled component serial
- **THEN** at most one active return SHALL consume that serial
- **AND** the other attempt SHALL fail without creating partial POS Return or Sales Return effects

#### Scenario: Non-consuming return releases eligibility
- **WHEN** the earlier return is rejected, deleted, or reaches another state defined as non-consuming by the existing return lifecycle
- **THEN** the serial SHALL become eligible for a new return if all other historical and current-state rules permit it

