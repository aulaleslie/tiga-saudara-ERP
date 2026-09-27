## MODIFIED Requirements

### Requirement: Role creation form displays all available permissions
The role creation form SHALL display all available permissions organized by feature group. Users SHALL be able to select permissions by checking/unchecking checkboxes. After normalization, the form structure is simplified and uses centralized configuration instead of hardcoded permission lists.

#### Scenario: Create form loads with grouped permissions
- **WHEN** a user navigates to the role creation page (/roles/create)
- **THEN** the form displays permissions organized into feature groups (Adjustments, Penjualan, Retur Penjualan, POS, etc.)
- **AND** each group is displayed in a collapsible card
- **AND** each permission has a checkbox and human-readable label
- **AND** permissions are sourced from the centralized configuration, not hardcoded in the blade file

#### Scenario: User can select all permissions in a group
- **WHEN** a user clicks the "Pilih Semua" button for a feature group
- **THEN** all permissions in that group are checked
- **AND** the group-level toggle checkbox reflects the selection state
- **AND** individual permission changes update the group toggle state correctly

#### Scenario: User can select all permissions globally
- **WHEN** a user clicks the global "Beri Semua Hak Akses" checkbox
- **THEN** all permissions across all groups are checked
- **AND** all group-level toggle checkboxes are checked
- **AND** unchecking an individual permission unchecks the global toggle

#### Scenario: Form submission validates required fields
- **WHEN** a user submits the role creation form
- **THEN** the role name is required (not empty)
- **AND** at least one permission must be selected
- **AND** all selected permissions must exist in the system
- **AND** the form uses the centralized configuration to validate permission names

### Requirement: Role edit form displays and preserves existing permissions
The role edit form SHALL display all available permissions with checkboxes, pre-checking the permissions already assigned to the role. After normalization, this form is simplified to use centralized configuration instead of hardcoded lists, and uses a helper function to pre-populate the form.

#### Scenario: Edit form loads with existing permissions pre-checked
- **WHEN** a user navigates to the role edit page for an existing role
- **THEN** the form displays all permissions organized by feature group
- **AND** permissions currently assigned to the role are pre-checked
- **AND** form validation respects previously validated input (old permissions)
- **AND** permissions are sourced from the centralized configuration

#### Scenario: User can modify role permissions
- **WHEN** a user modifies the permission checkboxes and submits the role edit form
- **THEN** the role's permissions are synchronized to exactly the selected permissions
- **AND** permissions added are assigned to the role
- **AND** permissions removed are revoked from the role
- **AND** all changes are validated against the centralized configuration

#### Scenario: Role edit form supports the same group selection features as create
- **WHEN** a user interacts with the group toggle or global toggle on the edit form
- **THEN** the behavior is identical to the create form (select/deselect groups, track state)
- **AND** pre-checked permissions do not prevent group toggles from working correctly

### Requirement: Permission configuration is shared between create and edit forms
Both the role creation and role editing forms SHALL reference the same centralized permission configuration, eliminating duplication and ensuring consistency.

#### Scenario: Both forms access the same permission groups
- **WHEN** create.blade.php and edit.blade.php render
- **THEN** both forms source their permission groups from the same location (e.g., a helper function or config)
- **AND** the permission groups are identical in both forms
- **AND** if the configuration is updated, both forms reflect the change without requiring view modifications

#### Scenario: Permission group display logic is not duplicated
- **WHEN** a developer needs to modify how permission groups are displayed
- **THEN** they make the change in one location (helper function / config)
- **AND** the change is applied to both create and edit forms
- **AND** no hardcoded `$permissionGroups` arrays exist in the view files

## REMOVED Requirements

### Requirement: Hardcoded permission group arrays in blade views
**Reason**: Replaced by centralized configuration and helper functions. Hardcoding created duplication and inconsistency between create and edit forms, and made maintenance difficult.

**Migration**:
- Extract permission groups from `create.blade.php` and `edit.blade.php`
- Move to centralized configuration in `app/Config/Permissions.php`
- Create helper function `PermissionHelper::getGroupsForForm()` to access groups
- Both views call the helper instead of defining the array

**Removed from**:
- `Modules/User/Resources/views/roles/create.blade.php` (lines 55-389, the `$permissionGroups` PHP block)
- `Modules/User/Resources/views/roles/edit.blade.php` (lines 54-398, the `$permissionGroups` PHP block)
