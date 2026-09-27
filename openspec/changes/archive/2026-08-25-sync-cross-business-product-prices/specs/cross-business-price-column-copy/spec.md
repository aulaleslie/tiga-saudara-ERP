## ADDED Requirements

### Requirement: Changed editable prices expose an apply-to-all control
The cross-business product price page SHALL provide an apply-to-all control beside every modifiable price field and SHALL show that control only when the field's current numeric value differs from its originally loaded value.

#### Scenario: Unchanged field keeps its control hidden
- **WHEN** an authorized user enters edit mode and a modifiable price field still equals its originally loaded numeric value
- **THEN** the apply-to-all control beside that field MUST remain hidden

#### Scenario: Changed field reveals its control
- **WHEN** an authorized user changes a modifiable price field to a numeric value different from its originally loaded value
- **THEN** the apply-to-all control beside that field MUST become visible

#### Scenario: Equivalent masked values are unchanged
- **WHEN** formatting differs but the current masked price and the originally loaded price represent the same numeric value
- **THEN** the field MUST be treated as unchanged and its apply-to-all control MUST remain hidden

#### Scenario: Restored field hides its control
- **WHEN** a changed field is restored to its originally loaded numeric value
- **THEN** its apply-to-all control MUST become hidden again

### Requirement: Apply-to-all copies only the selected price column
Activating an apply-to-all control SHALL copy the source field's current numeric value into the corresponding modifiable price field for every displayed business without changing other price columns.

#### Scenario: User applies a selling price to every business
- **WHEN** the user activates the apply-to-all control beside a changed selling-price field
- **THEN** every business row's selling-price field MUST contain the source value
- **AND** tier prices and purchase prices MUST remain unchanged

#### Scenario: Propagated fields participate in change detection
- **WHEN** an apply-to-all action changes a target field away from that target's originally loaded value
- **THEN** the target field MUST be treated as changed and its apply-to-all control MUST become visible
- **AND** a target whose resulting value equals its original value MUST keep its control hidden

### Requirement: Column copying preserves explicit save and cancel behavior
Applying a value to a column SHALL only change the form state; persistence SHALL continue to require the existing Save action, and Cancel SHALL restore all modifiable fields to their originally loaded values.

#### Scenario: Apply-to-all does not save immediately
- **WHEN** the user activates an apply-to-all control but does not activate Save
- **THEN** no product price SHALL be persisted

#### Scenario: User saves propagated values
- **WHEN** the user applies a value to a column and activates Save
- **THEN** the existing cross-business price submission SHALL persist the submitted rows through its existing validation, authorization, transaction, and optimistic-locking behavior

#### Scenario: User cancels propagated values
- **WHEN** the user applies a value to a column and activates Cancel
- **THEN** every modifiable field MUST return to its originally loaded value
- **AND** every apply-to-all control MUST be hidden

