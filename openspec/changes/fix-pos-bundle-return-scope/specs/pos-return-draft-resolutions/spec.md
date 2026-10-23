# Spec Delta

## MODIFIED Requirements

### Requirement: Bundle Draft Resolutions SHALL Separate Refund And Replacement Eligibility
The system SHALL support whole-bundle and explicitly selected parent-only cash returns while preserving existing independent product-replacement eligibility. Whole-bundle cash return MUST include every original component proportionally. All identities MUST derive from the original persisted transaction.

#### Scenario: Whole bundle quantity is selected for cash return
- **WHEN** a user selects whole-bundle cash return for one or more units of a bundled POS line
- **THEN** the draft SHALL include the selected parent quantity and the proportional quantity of every originally fulfilled component
- **AND** the expected refund SHALL equal the original captured customer bundle amount for that quantity

#### Scenario: Bundle parent alone can be explicitly refunded
- **WHEN** a user explicitly selects parent-only cash return for a bundle parent
- **THEN** the draft SHALL include only the selected parent quantity at its captured allocated value
- **AND** retained components and their allocated charges SHALL remain unchanged

#### Scenario: Bundle parent alone cannot be refunded
- **WHEN** whole-bundle scope cannot resolve every original component for the selected quantity
- **THEN** the draft SHALL reject the selection with an actionable error

#### Scenario: Bundle component alone cannot be refunded
- **WHEN** a user attempts cash return for an individual bundle component
- **THEN** the draft SHALL reject the selection
- **AND** the component's internal allocation SHALL NOT become a customer refund amount

#### Scenario: Bundle parent can be replaced independently
- **WHEN** a user selects product replacement for a bundle parent
- **THEN** the draft SHALL allow the parent replacement without selecting its components
- **AND** the remaining bundle composition SHALL have no action

#### Scenario: Bundle component can be replaced independently
- **WHEN** a user selects product replacement for a persisted bundle component
- **THEN** the draft SHALL retain the exact SaleBundleItem, dispatch, owner, location, product, and serial lineage for that component
- **AND** the parent and other components SHALL have no action

## ADDED Requirements

### Requirement: Bundle cash-return scope is explicit and durable
New bundle cash-return selections SHALL default to `whole_bundle`. Users SHALL be able to explicitly choose `parent_only` per selected parent and quantity. The system MUST preserve scope through create, edit, submit, approval, and detail display, and MUST reject unknown scope values. Existing returns without scope MUST NOT be inferred as intentional parent-only selections.

#### Scenario: Whole bundle is the default
- **WHEN** a user selects cash return for an original bundle
- **THEN** whole bundle is selected and its included components are visible

#### Scenario: Parent-only choice survives editing
- **WHEN** a user saves and reopens an explicitly parent-only draft
- **THEN** parent-only remains selected and retained component charges are visible

#### Scenario: Partial selected quantity
- **WHEN** a user returns one of three bundles with two components per bundle
- **THEN** whole bundle selects one parent and two components, while parent only selects one parent

#### Scenario: Legacy pending draft lacks scope
- **WHEN** a pending legacy bundle return has no explicit scope
- **THEN** final approval is blocked until an authorized user reviews and resubmits explicit scope
- **AND** completed historical returns remain unchanged

#### Scenario: Whole-bundle vs parent-only charge consequences
- **GIVEN** a bundled sale with customer bundle price Rp890,000 comprising parent camera (allocated Rp630,000) and component microSD (allocated Rp260,000)
- **WHEN** `whole_bundle` scope is selected for cash return
- **THEN** the expected reduction/refund equals the full Rp890,000 customer bundle amount and all components are reversed
- **WHEN** `parent_only` scope is selected for cash return
- **THEN** the expected reduction/refund equals the parent allocated amount of Rp630,000
- **AND** the component microSD charge of Rp260,000 is retained without reversal
