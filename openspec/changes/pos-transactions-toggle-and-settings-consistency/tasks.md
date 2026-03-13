## 1. Settings Page Toggle

- [ ] 1.1 Add `pos_transactions_enabled` checkbox to `Modules/Setting/Resources/views/index.blade.php` in the POS row, next to `pos_enabled`
- [ ] 1.2 Add JS to show/hide `pos_transactions_enabled` when `pos_enabled` is toggled
- [ ] 1.3 Wire `pos_transactions_enabled` in `SettingController@update` (`$data` array and `$fieldsToSync` array)

## 2. Page Title Consistency

- [ ] 2.1 Fix `businesses/create.blade.php`: change `@section('title', 'Edit Settings')` to `@section('title', 'Tambah Bisnis')` and breadcrumb to "Tambah Bisnis"
- [ ] 2.2 Fix `businesses/edit.blade.php`: change `@section('title', 'Edit Settings')` to `@section('title', 'Ubah Bisnis')` and breadcrumb to "Ubah Bisnis"
- [ ] 2.3 Fix `index.blade.php`: change `@section('title', 'Ubah Pengaturan Perusahaan')` to `@section('title', 'Pengaturan Bisnis')` and breadcrumb to "Pengaturan"

## 3. Verification

- [ ] 3.1 Verify settings page saves `pos_transactions_enabled` correctly (manual or tinker check)
- [ ] 3.2 Verify the "Simpan dan Buka Baru" button becomes active on `/pos/sell` after enabling toggle
- [ ] 3.3 Verify page titles are correct on all three pages
