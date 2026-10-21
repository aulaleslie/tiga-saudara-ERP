# Spec Delta

## ADDED Requirements

### Requirement: Preview discloses bundle scope and retained charges
Approval preview SHALL display each bundle parent's saved cash-return scope, selected quantities, included and retained components, original owner/location and sale lineage, commercial reduction, actual refund, and debt reduction. Parent-only preview MUST disclose retained component charges. Opening preview MUST remain read-only.

#### Scenario: Unpaid whole bundle
- **WHEN** preview evaluates the reported Rp890,000 unpaid bundle with whole-bundle scope
- **THEN** it shows camera Rp630,000 and microSD Rp260,000 commercial reductions across their owner sales
- **AND** it shows Rp890,000 debt reduction and zero actual cash refund

#### Scenario: Unpaid parent only
- **WHEN** preview evaluates the same bundle with explicit parent-only scope
- **THEN** it shows Rp630,000 debt reduction and zero actual cash refund
- **AND** it shows the retained microSD and Rp260,000 remaining billable

#### Scenario: Scope and composition are revalidated
- **WHEN** final approval receives stale scope, quantities, source mapping, or financial evidence
- **THEN** the server rebuilds the plan and blocks execution if the reviewed intent cannot be safely honored
- **AND** it does not substitute whole bundle or parent only silently

## MODIFIED Requirements

### Requirement: Approval Preview SHALL Disclose Replacement Execution Mode
The approval preview SHALL distinguish serial inventory replacement from non-serial note-only replacement before final confirmation and SHALL show enough persisted lineage for the approver to verify the affected bundle parent or component. Cash-return preview MUST honor the saved whole-bundle or parent-only scope.

#### Scenario: Serial component replacement preview
- **WHEN** a pending return replaces a serial-tracked bundle component
- **THEN** preview SHALL show the bundle and component identity, original Sale and dispatch lineage, returned serial, replacement serial, source owner/location, replacement owner/location, and planned serial movements
- **AND** it SHALL show zero customer refund and no original Sale commercial correction

#### Scenario: Non-serial replacement preview
- **WHEN** a pending return replaces a non-serial product
- **THEN** preview SHALL label the action as note-only
- **AND** it SHALL state that approval creates no receiving, dispatch, inventory, or HPP movement and that physical exchange or breakage is handled separately

#### Scenario: Whole-bundle refund preview
- **WHEN** a pending return cash-refunds a bundled quantity with whole-bundle scope
- **THEN** preview SHALL show the complete persisted parent/component composition, original owner/location lineage, and the single customer-facing refund amount
- **AND** it SHALL not present component allocations as separate customer refunds
