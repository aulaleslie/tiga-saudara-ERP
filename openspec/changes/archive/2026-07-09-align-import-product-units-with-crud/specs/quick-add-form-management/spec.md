## ADDED Requirements

### Requirement: Disabled fields MUST NOT expose active quick-add controls
When a selectable field is disabled, read-only, or locked by workflow state, the system SHALL prevent users from creating selectable values through that field's quick-add control.

#### Scenario: Locked product unit hides quick-add action
- **WHEN** a user opens the Product edit page for a product whose unit field is locked
- **THEN** the Unit Utama selector MUST NOT expose an active quick-add button for creating a new unit
- **AND** the locked selector MUST continue to display the product's current unit value when available

#### Scenario: Enabled product unit keeps quick-add action
- **WHEN** a user opens a Product create or editable Product edit unit field where unit selection is enabled
- **AND** the field allows quick creation
- **THEN** the Unit Utama selector MUST expose the quick-add button for creating a new unit

#### Scenario: Disabled selectable fields do not offer value mutation actions
- **WHEN** any searchable dropdown field is rendered disabled or read-only
- **THEN** field-adjacent actions that would mutate the selected value or create a new selectable value MUST be hidden or disabled
- **AND** the field's submitted value MUST continue to be preserved according to the form's existing disabled-field handling
