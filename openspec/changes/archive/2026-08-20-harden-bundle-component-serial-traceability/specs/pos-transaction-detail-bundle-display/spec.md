## ADDED Requirements

### Requirement: Transaction detail SHALL display persisted component serial lineage
POS transaction detail SHALL show serialized bundle components and their persisted serials as part of the bundle composition, separately from parent-line serials and from standalone occurrences of the same SKU.

#### Scenario: Same SKU appears standalone and bundled
- **WHEN** a completed transaction contains Product A as a standalone serialized line and as a serialized component of Product B
- **THEN** transaction detail SHALL display each serial under its correct standalone or bundle position

#### Scenario: Component was fulfilled by another owner
- **WHEN** a serialized component was fulfilled through a split-owner Sale and dispatch
- **THEN** transaction detail SHALL retain the customer-facing bundle grouping while presenting the serial recorded for that component fulfillment

