## ADDED Requirements

### Requirement: Dispatch submission SHALL reserve only outstanding fulfillment demand
The system SHALL create a standard Sales dispatch only after locking the Sale fulfillment boundary and recalculating authoritative outstanding demand from saved parent rows, saved bundle-component rows, and existing pending or approved dispatch details, inside the same transaction that persists the new dispatch.

#### Scenario: Concurrent submissions target the same remaining quantity
- **WHEN** two dispatch submissions concurrently request the same last outstanding quantity for one fulfillment key
- **THEN** at most one submission SHALL create pending dispatch demand for that quantity
- **AND** the other submission SHALL fail without creating a dispatch header, detail, notification, or inventory effect.

### Requirement: Dispatch approval SHALL apply inventory effects exactly once
The system MUST lock and revalidate the persisted dispatch inside the approval transaction, before any stock, serial, inventory-transaction, notification-resolution, or Sale-status side effect. Only a dispatch that is still pending under that lock SHALL transition to approved.

#### Scenario: Concurrent approval requests target one pending dispatch
- **WHEN** two approval requests concurrently target the same pending dispatch
- **THEN** exactly one request SHALL transition the dispatch to approved and apply its fulfillment effects
- **AND** the other request SHALL observe that the dispatch is already processed and SHALL create no additional side effect.

### Requirement: Dispatch-detail fulfillment routing SHALL be immutable
Each standard Sales dispatch detail SHALL persist a server-authored decision, captured at submission time, indicating whether approval requires inventory fulfillment or audit-only non-stock acknowledgement. Approval SHALL follow that persisted decision and SHALL reject an incompatible live product classification rather than silently switching routes.

#### Scenario: Stock-managed detail becomes non-stock before approval
- **WHEN** a pending inventory-fulfillment detail references a product that is reclassified as non-stock before approval
- **THEN** approval SHALL fail with an actionable classification-conflict message
- **AND** the system SHALL NOT skip the originally required inventory movement.

#### Scenario: Non-stock detail becomes stock-managed before approval
- **WHEN** a pending audit-only detail references a product that is reclassified as stock-managed before approval
- **THEN** approval SHALL fail with an actionable classification-conflict message
- **AND** the system SHALL NOT reinterpret the audit detail as inventory demand.

#### Scenario: Historical detail has no explicit routing snapshot
- **WHEN** a pre-change pending dispatch detail has no persisted routing decision
- **THEN** the system SHALL apply a deterministic compatibility rule based on its persisted inventory-specific fields
- **AND** it SHALL reject approval when safe routing cannot be determined.
