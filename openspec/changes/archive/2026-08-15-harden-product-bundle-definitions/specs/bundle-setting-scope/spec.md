## MODIFIED Requirements

### Requirement: Bundle belongs to a setting
Every product bundle record SHALL have a `setting_id` foreign key referencing the `settings` table. This column SHALL NOT be nullable. One bundle-creation operation SHALL create a separate record for each setting available at creation time.

#### Scenario: Bundle copies created with setting IDs
- **WHEN** a user creates a new bundle for a product while settings exist
- **THEN** the system SHALL store exactly one new bundle copy for each currently available setting
- **AND** each copy SHALL have `setting_id` equal to its target setting

#### Scenario: Database constraint
- **WHEN** a bundle record exists in `product_bundles`
- **THEN** its `setting_id` MUST reference a valid row in the `settings` table

### Requirement: Bundle CRUD scoped to active setting
Bundle listing, editing, enabling, disabling, and deletion on the product detail page SHALL be scoped to the user's active `session('setting_id')`. Creation SHALL use the active-setting authoring context but SHALL atomically create independent copies for all settings currently available without requiring manual setting selection.

#### Scenario: Listing bundles on product detail page
- **WHEN** a user views the product detail page (`/products/{id}`)
- **THEN** only bundles matching the user's active `setting_id` SHALL be displayed

#### Scenario: Creating a bundle
- **WHEN** a user creates a bundle via `/products/{id}/bundles/create`
- **THEN** one copy with identical parent, component products, quantities, configured prices, dates, and initial enabled state SHALL be stored for every currently available setting
- **AND** the user SHALL NOT need to manually select target settings

#### Scenario: Editing a bundle from another setting
- **WHEN** a user attempts to edit a bundle whose `setting_id` does not match the user's active setting
- **THEN** the system SHALL return a 404 response

#### Scenario: Deleting a bundle from another setting
- **WHEN** a user attempts to delete a bundle whose `setting_id` does not match the user's active setting
- **THEN** the system SHALL return a 404 response

## ADDED Requirements

### Requirement: All-setting bundle creation SHALL be atomic
Creation of a bundle and all of its setting-specific component rows SHALL execute as one database transaction. The operation SHALL not leave a partial set of setting copies.

#### Scenario: Every setting copy succeeds
- **WHEN** bundle headers and all component rows can be persisted for every currently available setting
- **THEN** the transaction SHALL commit every setting copy

#### Scenario: One setting copy fails
- **WHEN** any bundle header or component row fails to persist for any target setting
- **THEN** the transaction SHALL roll back all headers and component rows created by the operation

### Requirement: Replicated bundle copies SHALL be independent after creation
Each replicated bundle SHALL be an independently managed per-setting record. Updating, enabling, disabling, or deleting one copy SHALL NOT propagate to another setting, and this change SHALL NOT introduce a shared replica-group identity.

#### Scenario: Edit one setting copy
- **WHEN** an administrator edits the composition or configuration of a bundle copy in Setting A
- **THEN** copies in all other settings SHALL remain unchanged

#### Scenario: Delete one setting copy
- **WHEN** an administrator deletes a bundle copy in Setting A
- **THEN** copies in all other settings SHALL remain present and unchanged

#### Scenario: Setting is created after bundle creation
- **WHEN** a new setting is added after a bundle creation operation has completed
- **THEN** this capability SHALL NOT automatically create a bundle copy for the new setting

