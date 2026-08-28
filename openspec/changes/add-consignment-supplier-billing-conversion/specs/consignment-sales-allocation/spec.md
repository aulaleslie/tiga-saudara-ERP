## MODIFIED Requirements

### Requirement: One-supplier confirmations govern reservation and approval
The system SHALL provide setting-scoped consignment billing confirmations belonging to exactly one supplier with allocation lifecycle `DRAFT`, `WAITING_APPROVAL`, `APPROVED`, or `REJECTED`. Drafts SHALL NOT reserve quantity; successful submission SHALL reserve it; approval SHALL convert reservations into immutable allocations and mark the confirmation ready for billing; rejection SHALL release reservations while preserving audit evidence. A successful Phase 3 conversion SHALL retain the `APPROVED` allocation state, link exactly one Purchase, clear further billing readiness, and preserve all earlier allocation evidence.

#### Scenario: Valid draft is submitted
- **WHEN** an authorized user submits a valid one-supplier confirmation
- **THEN** its status SHALL become `WAITING_APPROVAL`
- **AND** sold-source and receipt-pool quantities SHALL be reserved atomically

#### Scenario: Pending confirmation is approved
- **WHEN** an authorized approver approves a still-valid waiting confirmation
- **THEN** its allocations and commercial/source snapshots SHALL become immutable
- **AND** its reservations SHALL become approved allocations exactly once
- **AND** it SHALL become ready for supplier billing

#### Scenario: Pending confirmation is rejected
- **WHEN** an authorized approver rejects a waiting confirmation with a required reason
- **THEN** its reservations SHALL be released
- **AND** actor, time, reason, and prior submission evidence SHALL be retained

#### Scenario: Rejected confirmation is revised and resubmitted
- **WHEN** an authorized user revises and submits a rejected confirmation with currently valid quantities
- **THEN** new reservations SHALL be established from authoritative capacity
- **AND** earlier rejection evidence SHALL remain auditable

#### Scenario: Approved confirmation is billed
- **WHEN** Phase 3 successfully converts an approved billing-ready confirmation
- **THEN** the confirmation SHALL remain `APPROVED` and link exactly one generated Purchase
- **AND** it SHALL no longer be eligible for another conversion
- **AND** its sold-source, receipt-allocation, serialized-allocation, and approval evidence SHALL remain unchanged
