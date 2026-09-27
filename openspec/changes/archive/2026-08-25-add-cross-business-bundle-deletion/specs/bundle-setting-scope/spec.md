## MODIFIED Requirements

### Requirement: Replicated bundle copies SHALL be independently managed except for opted-in group actions
Each replicated bundle SHALL remain an independently managed per-setting record by default. Updating, enabling, disabling, or deleting one copy SHALL NOT propagate to another setting, except that an authorized administrator MAY explicitly synchronize `bundle_sale_price` or explicitly delete all existing copies with the same non-null replica-group identity. Each cross-business action SHALL be independently opted into and SHALL affect no unrelated field or replica group.

#### Scenario: Edit one setting copy without synchronization
- **WHEN** an administrator edits a grouped bundle copy without selecting cross-business price synchronization
- **THEN** only the active-setting copy SHALL be updated
- **AND** copies in all other settings SHALL remain unchanged

#### Scenario: Synchronize only the bundle sale price
- **WHEN** an administrator edits a grouped bundle copy and selects cross-business price synchronization
- **THEN** every existing bundle copy with the same replica-group identity SHALL receive the submitted `bundle_sale_price`
- **AND** only the active-setting copy SHALL receive submitted changes to name, description, active dates, enabled state, composition, and informational component prices

#### Scenario: Delete one setting copy by default
- **WHEN** an administrator confirms deletion without selecting cross-business deletion
- **THEN** only the authorized active-setting bundle copy SHALL be deleted
- **AND** copies in all other settings SHALL remain present and unchanged

#### Scenario: Delete every existing group copy explicitly
- **WHEN** an administrator confirms deletion of a grouped bundle and explicitly selects cross-business deletion
- **THEN** every existing bundle copy with the route bundle's persisted non-null replica-group identity SHALL be deleted
- **AND** bundles with a different or null replica-group identity SHALL remain unchanged

#### Scenario: Setting is created after bundle creation
- **WHEN** a new setting is added after a bundle creation operation has completed
- **THEN** this capability SHALL NOT automatically create a bundle copy for the new setting
- **AND** later synchronized price or delete actions SHALL target only existing copies carrying the same replica-group identity

## ADDED Requirements

### Requirement: Bundle deletion SHALL use an application confirmation modal
The Product Bundle administration surface SHALL use an Indonesian application-native Bootstrap modal to confirm deletion and SHALL NOT use the browser-native `window.confirm()` dialog for bundle deletion. One reusable modal MAY serve multiple bundle rows but SHALL submit the route and bundle selected by the triggering action.

#### Scenario: Administrator opens bundle deletion confirmation
- **WHEN** an authorized administrator activates `Hapus Paket` for a displayed bundle
- **THEN** the page SHALL open a modal titled `Hapus Paket Penjualan`
- **AND** the modal SHALL identify the selected bundle by name
- **AND** it SHALL warn that deletion cannot be undone
- **AND** no deletion request SHALL be submitted until the administrator activates the modal's destructive confirmation button

#### Scenario: Administrator cancels deletion
- **WHEN** the administrator closes the modal or activates `Batal`
- **THEN** the modal SHALL close
- **AND** no bundle SHALL be deleted

#### Scenario: Modal submits selected bundle route
- **WHEN** the administrator opens deletion for one bundle and confirms it
- **THEN** the modal form SHALL submit the DELETE route for that selected product and bundle
- **AND** SHALL NOT submit a previously selected or different bundle's route

### Requirement: Grouped bundle deletion SHALL provide explicit cross-business control
For a bundle with a non-null replica-group identity, the deletion modal SHALL show an unchecked checkbox labeled `Hapus paket ini dari semua bisnis`. The control SHALL apply only to the current deletion confirmation and SHALL default to active-business deletion when not selected.

#### Scenario: Grouped bundle modal displays unchecked option
- **WHEN** deletion confirmation opens for a bundle with a non-null replica-group identity
- **THEN** the modal SHALL show `Hapus paket ini dari semua bisnis`
- **AND** the checkbox SHALL be unchecked each time the modal opens

#### Scenario: Historical bundle receives local-only guidance
- **WHEN** deletion confirmation opens for a bundle whose replica-group identity is null
- **THEN** the modal SHALL not provide an actionable cross-business deletion checkbox
- **AND** it SHALL explain `Bundle lama tidak terhubung dengan salinan bisnis lainnya dan hanya akan dihapus dari bisnis ini.`

### Requirement: Cross-business bundle deletion SHALL be atomic and lineage-scoped
An opted-in cross-business deletion SHALL execute in one database transaction and SHALL select targets solely from the authorized route bundle's persisted non-null replica-group identity. Client input SHALL NOT choose or override the replica-group identity, and a null identity SHALL never be used as a group deletion predicate.

#### Scenario: Successful grouped deletion
- **WHEN** every existing matching bundle copy and its dependent component rows can be deleted
- **THEN** the transaction SHALL commit deletion of every copy with the persisted replica-group identity

#### Scenario: One grouped deletion fails
- **WHEN** any matching bundle deletion fails after the transaction begins
- **THEN** the transaction SHALL roll back deletion of the route bundle and every other group copy

#### Scenario: Forged lineage input cannot redirect deletion
- **WHEN** a client submits another existing group's replica-group identity with a grouped delete request
- **THEN** the system SHALL ignore or reject the submitted identity
- **AND** SHALL derive deletion targets only from the authorized route bundle
- **AND** the unrelated submitted group SHALL remain unchanged

#### Scenario: Null lineage cannot fan out
- **WHEN** a client requests cross-business deletion for an authorized historical bundle with a null replica-group identity
- **THEN** the system SHALL delete at most the authorized route bundle
- **AND** other null-lineage bundles SHALL remain unchanged

#### Scenario: Partial group deletion
- **WHEN** one or more original group members were already deleted and cross-business deletion is confirmed for a surviving member
- **THEN** the system SHALL delete every surviving member with that replica-group identity
- **AND** SHALL NOT require or recreate missing members

#### Scenario: Existing authorization remains enforced
- **WHEN** a user lacks bundle-delete permission, uses a mismatched nested product, or targets a bundle outside the active setting
- **THEN** the system SHALL reject the request before deleting the route bundle or any replica-group member

