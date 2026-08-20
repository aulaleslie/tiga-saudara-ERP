## ADDED Requirements

### Requirement: Split posting snapshots only physical group fulfillment
POS split posting SHALL persist parent HPP only in groups that physically fulfill parent stock and component HPP only in groups that physically fulfill the corresponding component stock. The Sale owner for each split group SHALL be the physical stock owner used for that group's first-choice average-cost lookup.

#### Scenario: Component-only group carries no parent HPP
- **WHEN** a split group contains component allocation and revenue but its `parent_not_fulfilled_by_group` marker is true
- **THEN** its logical parent Sale detail SHALL contribute zero parent HPP with a not-fulfilled source
- **AND** its fulfilled components SHALL retain their own component HPP snapshots

#### Scenario: Parent and component have different owners
- **WHEN** the parent and a component are fulfilled by different source settings
- **THEN** the parent snapshot SHALL first use the parent stock owner's average
- **AND** the component snapshot SHALL first use the component stock owner's average
- **AND** neither snapshot SHALL use the POS owner's informational component revenue price as cost

#### Scenario: Split retry is idempotent
- **WHEN** checkout finalization is retried with the same idempotency identity
- **THEN** it SHALL not create or recognize duplicate parent or component cost snapshots

