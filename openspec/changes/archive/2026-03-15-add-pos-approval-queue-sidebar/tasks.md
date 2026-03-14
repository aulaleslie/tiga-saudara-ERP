## 1. Implementation

- [x] 1.1 Add "Antrian Persetujuan" link to `resources/views/layouts/menu.blade.php` inside the POS dropdown.
- [x] 1.2 Apply `@if($posEnabledForCurrentSetting)` and `@can('pos.supervisor.approval')` guards to the new link.

## 2. Verification

- [x] 2.1 Verify sidebar link visibility for user with `pos.supervisor.approval` permission.
- [x] 2.x Verify link visibility triggers POS menu group visibility.
- [x] 2.2 Verify link is hidden for user without the permission.
- [x] 2.3 Verify link is accessible without an active POS session.
