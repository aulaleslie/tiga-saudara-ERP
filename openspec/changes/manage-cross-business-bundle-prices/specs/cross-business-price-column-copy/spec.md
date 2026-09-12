## ADDED Requirements

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

