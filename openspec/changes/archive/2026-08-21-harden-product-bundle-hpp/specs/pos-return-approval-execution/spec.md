## ADDED Requirements

### Requirement: Effective bundle returns reverse immutable HPP
Final POS Return approval SHALL derive parent and component HPP reversal from the original persisted Sale detail or Sale bundle item snapshot and the physical quantity received. Draft, pending, warned, blocked, rejected, or rolled-back returns MUST NOT affect recognized HPP.

#### Scenario: Partial bundle cash return reverses proportional cost
- **WHEN** final approval receives part of an originally fulfilled bundle parent and its mapped stock-managed components
- **THEN** it SHALL persist parent and component return HPP equal to original unit snapshots multiplied by physically received quantities
- **AND** reports SHALL subtract those reversals exactly once

#### Scenario: Return does not reload current average cost
- **WHEN** current average prices or the live bundle definition differ from the original Sale
- **THEN** return HPP SHALL continue using original persisted parent and component cost identity

#### Scenario: Rejected return has no HPP effect
- **WHEN** a POS Return is rejected or final approval rolls back
- **THEN** no return HPP reversal SHALL become effective

### Requirement: Replacement dispatch has independent HPP
A product-replacement return SHALL reverse HPP for the physically received original item and SHALL recognize new outbound HPP for the replacement item when its approved replacement dispatch becomes effective. Bundle components that are informational-only in replacement execution SHALL neither reverse nor create HPP.

#### Scenario: Parent-only bundle replacement
- **WHEN** a bundle replacement receives and dispatches only the parent product
- **THEN** original parent HPP SHALL be reversed for the received quantity
- **AND** replacement parent HPP SHALL be snapshotted independently at replacement dispatch
- **AND** component HPP SHALL remain unchanged

#### Scenario: Replacement retry is idempotent
- **WHEN** replacement execution is retried after its HPP effects were persisted
- **THEN** neither original reversal nor replacement outgoing HPP SHALL be duplicated

