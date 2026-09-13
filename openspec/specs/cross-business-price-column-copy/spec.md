# cross-business-price-column-copy Specification

## Purpose
TBD - created by archiving change sync-cross-business-product-prices. Update Purpose after archive.
## Requirements
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

### Requirement: Conversion copying is scoped by conversion identity
Conversion price fields SHALL expose apply-to-all controls in edit mode only for valid numeric values changed from their loaded state, treating missing as distinct from zero. Copying SHALL target only the same conversion ID across all displayed businesses and SHALL NOT change other conversion columns, base price columns, factors, or enablement flags.

#### Scenario: Copy one conversion
- **WHEN** a user changes KOTAK price and activates its apply-to-all control
- **THEN** every displayed business's KOTAK field SHALL receive that numeric value
- **AND** other conversion and base price fields SHALL remain unchanged

#### Scenario: Copy into a missing cell
- **WHEN** a conversion price is copied into a business cell that was missing
- **THEN** that cell SHALL contain the explicit copied value and be treated as changed

#### Scenario: Copy zero
- **WHEN** a user enters zero into a missing cell
- **THEN** its apply-to-all control SHALL become available
- **AND** copying SHALL propagate explicit zero only within that conversion column

#### Scenario: Copy remains unsaved until Save
- **WHEN** the user copies a conversion price without saving
- **THEN** the database SHALL remain unchanged
- **AND** Batal SHALL restore original numeric values and missing states and hide copy controls

#### Scenario: Save copied values
- **WHEN** the user saves copied conversion prices
- **THEN** they SHALL pass through the combined authorization, validation, atomic save, and stale-state checks

### Requirement: Bundle price copying is scoped to existing replica-group cells
Existing bundle-price fields SHALL expose apply-to-all controls in edit mode only for valid numeric values changed from their loaded state. Activating the control SHALL copy the source value only to existing editable bundle copies in the same persisted replica group across displayed businesses. It SHALL NOT affect another replica group, base prices, conversion prices, bundle metadata, or missing business/group combinations.

#### Scenario: User copies one bundle price across its group
- **WHEN** a user changes an existing bundle price and activates its apply-to-all control
- **THEN** every existing editable bundle copy in that replica-group column MUST receive the source value in form state
- **AND** bundle copies in every other group MUST remain unchanged

#### Scenario: Missing copy is not filled
- **WHEN** the user applies a bundle price to a group that is unavailable in one or more businesses
- **THEN** each unavailable cell MUST remain read-only and unavailable
- **AND** no input or bundle row MUST be created for that business/group combination

#### Scenario: Copy remains unsaved until Save
- **WHEN** a user copies a bundle price without activating Save
- **THEN** the database MUST remain unchanged
- **AND** activating `Batal` MUST restore every existing bundle cell's loaded value and hide its apply-to-all control

#### Scenario: Copied prices use combined save protections
- **WHEN** the user saves copied bundle prices
- **THEN** all copied existing cells MUST pass through the combined authorization, validation, atomic transaction, lineage verification, and stale-state checks

#### Scenario: Explicit zero is copied only to existing copies
- **WHEN** a user changes an existing bundle price to zero and applies it to all businesses
- **THEN** every existing editable copy in that replica group MUST receive explicit zero in form state
- **AND** unavailable cells MUST remain absent

