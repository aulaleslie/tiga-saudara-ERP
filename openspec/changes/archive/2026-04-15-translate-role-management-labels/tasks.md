## 1. UI View Translations

- [x] 1.1 Update `Modules/User/Resources/views/roles/create.blade.php` to replace "Role Name" with "Nama Peran"
- [x] 1.2 Update `Modules/User/Resources/views/roles/create.blade.php` to replace "Permissions" with "Hak Akses"

## 2. POS Matrix Translations

- [x] 2.1 Update `Modules/Pos/Support/PosPermissionMatrix.php` to translate the `supportedBundles` labels and descriptions to Bahasa Indonesia
- [x] 2.2 Update `Modules/Pos/Support/PosPermissionMatrix.php` to translate the `capabilityClusters` labels to Bahasa Indonesia

## 3. Configuration Translations

- [x] 3.1 Update `app/Config/Permissions.php` by translating all English UI group keys (e.g. `Adjustments`, `Brands`, `Chart of Accounts`) into Bahasa Indonesia
- [x] 3.2 Run config cache clear (`php artisan config:clear`) and verify that the role creation and edit forms correctly display the translated headers
