## Why

The role management UI currently has a mix of English and Bahasa Indonesia labels. While the action labels are translated, the permission group headers, POS guidance descriptions, and form labels in the create view are in English. This creates an inconsistent user experience for Indonesian speakers and needs to be standardized to full Bahasa Indonesia.

## What Changes

- Translate static form labels "Role Name" and "Permissions" to "Nama Peran" and "Hak Akses" in `Modules/User/Resources/views/roles/create.blade.php`.
- Translate all 40+ permission group keys in `app/Config/Permissions.php` to Bahasa Indonesia (e.g., "Adjustments" -> "Penyesuaian", "Chart of Accounts" -> "Bagan Akun").
- Translate POS bundle names and their text descriptions in `Modules/Pos/Support/PosPermissionMatrix.php` to Bahasa Indonesia.
- Update any database seeders or checks that might rely on the exact string of the English permission group names, if applicable (though they are mainly used for UI grouping).

## Capabilities

### New Capabilities
None.

### Modified Capabilities
None. This is purely a UI presentation and translation change that does not alter system behavior or capabilities.

## Impact

- **UI Views**: `create.blade.php` for roles.
- **Config**: `app/Config/Permissions.php` group keys will change, which will affect how the permission cards are rendered in the role `create` and `edit` views.
- **Code**: `Modules/Pos/Support/PosPermissionMatrix.php` descriptions will be updated.
- **Note**: The actual permission names (e.g., `purchases.access`) are NOT changing, which means the authorization logic across the system remains unaffected. Only the grouping and display labels are changing.
