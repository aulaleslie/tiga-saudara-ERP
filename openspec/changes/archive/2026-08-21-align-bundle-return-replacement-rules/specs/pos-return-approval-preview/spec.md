## ADDED Requirements

### Requirement: Approval Preview SHALL Disclose Replacement Execution Mode
The approval preview SHALL distinguish serial inventory replacement from non-serial note-only replacement before final confirmation and SHALL show enough persisted lineage for the approver to verify the affected bundle parent or component.

#### Scenario: Serial component replacement preview
- **WHEN** a pending return replaces a serial-tracked bundle component
- **THEN** preview SHALL show the bundle and component identity, original Sale and dispatch lineage, returned serial, replacement serial, source owner/location, replacement owner/location, and planned serial movements
- **AND** it SHALL show zero customer refund and no original Sale commercial correction

#### Scenario: Non-serial replacement preview
- **WHEN** a pending return replaces a non-serial product
- **THEN** preview SHALL label the action as note-only
- **AND** it SHALL state that approval creates no receiving, dispatch, inventory, or HPP movement and that physical exchange or breakage is handled separately

#### Scenario: Whole-bundle refund preview
- **WHEN** a pending return cash-refunds a bundled quantity
- **THEN** preview SHALL show the complete persisted parent/component composition, original owner/location lineage, and the single customer-facing refund amount
- **AND** it SHALL not present component allocations as separate customer refunds
