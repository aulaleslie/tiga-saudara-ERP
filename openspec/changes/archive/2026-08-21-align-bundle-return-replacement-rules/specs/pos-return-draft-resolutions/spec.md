## ADDED Requirements

### Requirement: Bundle Draft Resolutions SHALL Separate Refund And Replacement Eligibility
The system SHALL treat a bundle as one customer-facing refundable unit while treating its parent and components as independently replaceable physical products. Draft authoring MUST block incomplete bundle cash-refund intent, MUST allow an independently selected parent or component product replacement, and MUST derive all identities from the original persisted transaction.

#### Scenario: Whole bundle quantity is selected for cash return
- **WHEN** a user selects cash return for one or more units of a bundled POS line
- **THEN** the draft SHALL include the selected parent quantity and the proportional quantity of every originally fulfilled component
- **AND** the expected refund SHALL equal the original captured customer bundle amount for that quantity

#### Scenario: Bundle parent alone cannot be refunded
- **WHEN** a user attempts cash return for a bundle parent without its complete original component composition
- **THEN** the draft SHALL reject the selection with an actionable whole-bundle refund message

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

### Requirement: Replacement Draft SHALL Declare Serial Or Note-Only Execution
Each product-replacement draft line SHALL derive execution mode from the persisted product's serial-tracking classification. Serial-tracked lines MUST capture serial replacement identity; non-serial lines MUST be recorded as note-only replacement intent without promising automatic inventory movement.

#### Scenario: Serial replacement selects two serial identities
- **WHEN** a user selects replacement for a serial-tracked ordinary product, bundle parent, or bundle component
- **THEN** the draft SHALL require the exact originally sold serial and an eligible replacement serial of the same product
- **AND** it SHALL identify the action as a serial inventory replacement

#### Scenario: Non-serial replacement records note intent
- **WHEN** a user selects replacement for a non-serial ordinary product, bundle parent, or bundle component
- **THEN** the draft SHALL require product, quantity, reason, and origin identity without a replacement serial
- **AND** it SHALL identify the action as note-only with physical exchange and breakage handled manually
