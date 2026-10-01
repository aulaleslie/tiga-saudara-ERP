# Spec Delta

## ADDED Requirements

### Requirement: Full Purchase editing requires no pending receivals
The system SHALL reject a full commercial Purchase edit while any receival for that Purchase remains `PENDING`. Cancelling pending receivals SHALL happen only through the explicit, audited purchase-level receival cancellation, not as an implicit side effect of an ordinary edit request.

#### Scenario: Approved Purchase has a pending receival
- **WHEN** an authorized user attempts a full edit while a pending receival exists
- **THEN** the system rejects the edit
- **AND** preserves the Purchase lines and pending receival unchanged

#### Scenario: All pending receivals were explicitly cancelled
- **WHEN** the Purchase is otherwise fully editable and no pending receival remains
- **THEN** the existence of retained `CANCELLED` receival history does not by itself prevent the edit

### Requirement: Cancelled receivals are terminal and ineffective
The system SHALL treat `CANCELLED` as a terminal receival status. Cancelled receivals SHALL NOT be approved, rejected, reactivated, counted as delivery evidence, included in fulfillment calculations, or used to authorize supplier-shortfall completion.

#### Scenario: Approval is attempted for a cancelled receival
- **WHEN** a user or stale request attempts to approve a cancelled receival
- **THEN** the system rejects the request without changing inventory or Purchase state

#### Scenario: Purchase fulfillment is calculated
- **WHEN** a Purchase has approved and cancelled receivals
- **THEN** only positive quantities from `APPROVED` receivals contribute to fulfillment

### Requirement: Receival cancellation joins the Purchase mutation lock order
Purchase-level receival cancellation SHALL lock and reload authoritative Purchase, receival, detail, inventory, dependency, and serial data using the same deterministic ordering as approval and other Purchase mutations.

#### Scenario: Cancellation races with receiving approval
- **WHEN** cancellation and approval target lifecycle data for the same Purchase concurrently
- **THEN** the operations serialize
- **AND** the operation observing stale status fails without partial effects

