## MODIFIED Requirements

### Requirement: Bundle belongs to a setting
Every product bundle record SHALL have a `setting_id` foreign key referencing the `settings` table. This column SHALL NOT be nullable. One bundle-creation operation SHALL create a separate record for each setting available at creation time and SHALL assign the same non-null replica-group identity to all copies created by that operation. Bundle records that predate replica grouping MAY retain a null replica-group identity without backfill.

#### Scenario: Bundle copies created with setting IDs and shared identity
- **WHEN** a user creates a new bundle for a product while settings exist
- **THEN** the system SHALL store exactly one new bundle copy for each currently available setting
- **AND** each copy SHALL have `setting_id` equal to its target setting
- **AND** every copy from that creation operation SHALL have the same non-null replica-group identity
- **AND** that identity SHALL differ from the identity assigned by any other bundle creation operation

#### Scenario: Database setting constraint
- **WHEN** a bundle record exists in `product_bundles`
- **THEN** its `setting_id` MUST reference a valid row in the `settings` table

#### Scenario: Existing bundle remains ungrouped
- **WHEN** the replica-group schema change is applied to a bundle created before this capability
- **THEN** its replica-group identity SHALL remain null
- **AND** the migration SHALL NOT infer or backfill a group from its name, timestamps, composition, or other mutable attributes

### Requirement: Replicated bundle copies SHALL be independently managed except for opted-in sale-price synchronization
Each replicated bundle SHALL remain an independently managed per-setting record by default. Updating, enabling, disabling, or deleting one copy SHALL NOT propagate to another setting, except that an authorized edit MAY explicitly synchronize only `bundle_sale_price` across existing copies with the same non-null replica-group identity.

#### Scenario: Edit one setting copy without synchronization
- **WHEN** an administrator edits a grouped bundle copy without selecting cross-business price synchronization
- **THEN** only the active-setting copy SHALL be updated
- **AND** copies in all other settings SHALL remain unchanged

#### Scenario: Synchronize only the bundle sale price
- **WHEN** an administrator edits a grouped bundle copy and selects cross-business price synchronization
- **THEN** every existing bundle copy with the same replica-group identity SHALL receive the submitted `bundle_sale_price`
- **AND** only the active-setting copy SHALL receive submitted changes to name, description, active dates, enabled state, composition, and informational component prices

#### Scenario: Delete one setting copy
- **WHEN** an administrator deletes a bundle copy in Setting A
- **THEN** copies in all other settings SHALL remain present and unchanged

#### Scenario: Setting is created after bundle creation
- **WHEN** a new setting is added after a bundle creation operation has completed
- **THEN** this capability SHALL NOT automatically create a bundle copy for the new setting
- **AND** later price synchronization SHALL target only existing copies carrying the same replica-group identity

## ADDED Requirements

### Requirement: Bundle edit SHALL provide explicit cross-business price control
The grouped bundle edit surface SHALL render an unchecked checkbox next to `Harga Jual Paket` labeled `Terapkan harga ke semua bisnis`. The control SHALL affect only the current save operation and SHALL require explicit selection each time.

#### Scenario: Grouped bundle edit displays opt-in control
- **WHEN** an authorized administrator opens the edit page for a bundle with a non-null replica-group identity
- **THEN** the page SHALL show `Terapkan harga ke semua bisnis` next to `Harga Jual Paket`
- **AND** the checkbox SHALL be unchecked by default

#### Scenario: Validation redisplay preserves submitted choice
- **WHEN** a grouped bundle update fails validation after the administrator selected the checkbox
- **THEN** the edit form SHALL redisplay the checkbox as selected
- **AND** no bundle price SHALL have been changed

#### Scenario: Historical ungrouped bundle cannot synchronize
- **WHEN** an administrator edits a bundle whose replica-group identity is null
- **THEN** the system SHALL not offer an actionable cross-business price synchronization control
- **AND** the page SHALL explain in Indonesian that the older bundle is not linked to copies in other businesses

### Requirement: Cross-business bundle price update SHALL be atomic and lineage-scoped
An opted-in cross-business price update SHALL execute in the same database transaction as the active-setting bundle update and SHALL select propagation targets solely by the submitted route bundle's persisted non-null replica-group identity. Client input SHALL NOT choose or override that identity.

#### Scenario: Successful synchronized update
- **WHEN** the active-setting bundle update and every matching grouped price update succeed
- **THEN** the transaction SHALL commit the active-setting changes and all matching `bundle_sale_price` changes together

#### Scenario: One grouped price update fails
- **WHEN** any update in the synchronized save fails
- **THEN** the transaction SHALL roll back the active-setting bundle update and every propagated price update

#### Scenario: Unrelated bundle is not updated
- **WHEN** synchronization is selected for one grouped bundle
- **THEN** a bundle with a different or null replica-group identity SHALL remain unchanged even if its name, parent product, timestamps, or component composition match

#### Scenario: Forged group input is ignored
- **WHEN** a client submits a replica-group identity different from the route bundle's persisted identity
- **THEN** the system SHALL ignore or reject the submitted identity
- **AND** SHALL derive synchronization targets only from the authorized route bundle

