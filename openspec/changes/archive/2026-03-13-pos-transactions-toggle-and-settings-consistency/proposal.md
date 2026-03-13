## Why

The `Simpan dan Buka Baru` button on the POS sell page is gated behind `pos_transactions_enabled`, a boolean setting that defaults to `false` with **no admin UI toggle** to enable it. Additionally, the settings page (`/settings`), business create (`/businesses/create`), and business edit (`/businesses/{id}/edit`) pages have inconsistent page titles and labels — e.g. business create shows "Edit Settings" as the browser title.

## What Changes

- Add a **"Aktifkan Transaksi POS"** (`pos_transactions_enabled`) checkbox to the settings page, positioned next to the existing "Aktifkan POS" toggle
- Add the same toggle to the business create and business edit forms so POS transaction support can be set during business creation/editing
- Wire `pos_transactions_enabled` through `SettingController@update` and `BusinessController@store`/`@update`
- Fix page title inconsistencies across all three pages:
  - `/settings` → title "Pengaturan Bisnis", breadcrumb "Pengaturan"
  - `/businesses/create` → title "Tambah Bisnis", breadcrumb "Tambah Bisnis"
  - `/businesses/{id}/edit` → title "Ubah Bisnis", breadcrumb "Ubah Bisnis"

## Capabilities

### New Capabilities
- `pos-transactions-toggle`: Admin UI toggle (`pos_transactions_enabled`) on settings and business pages to control whether the POS "Simpan dan Buka Baru" feature is available

### Modified Capabilities
_(none — no spec-level behavior changes to existing capabilities)_

## Impact

- **Views**: `setting::index`, `setting::businesses.create`, `setting::businesses.edit`
- **Controllers**: `SettingController@update`, `BusinessController@store`, `BusinessController@update`
- **DB**: No migration needed — `pos_transactions_enabled` column already exists on `settings` table
