## Context

Three pages manage `settings` records:
- `/settings` (edit current business's setting) — `SettingController@update`
- `/businesses/create` — `BusinessController@store`
- `/businesses/{id}/edit` — `BusinessController@update`

The `pos_transactions_enabled` column already exists on the `settings` table (boolean, default `false`) but has no UI toggle. This flag gates the "Simpan dan Buka Baru" button on the POS sell page and the "Transaksi POS" menu item.

All three pages have inconsistent page titles/card headers.

## Goals / Non-Goals

**Goals:**
- Expose `pos_transactions_enabled` as a checkbox on the settings page next to `pos_enabled`
- Fix page titles (`@section('title')`) and breadcrumb labels for consistency
- Ensure `SettingController@update` persists the new field

**Non-Goals:**
- Adding POS toggles to business create/edit pages (they don't have POS section today)
- Changing POS transaction behaviour (already implemented)
- Reworking page layout or adding new UI components

## Decisions

**1. Toggle on settings page only**
Business create/edit don't have a POS section today (`pos_enabled`, `pos_walk_in_customer_id`). Adding only `pos_transactions_enabled` without the parent toggle would be confusing. Keep it on the settings page where the POS section already exists.

**2. Title consistency convention**

| Page | `@section('title')` | Card header | Breadcrumb trail |
|---|---|---|---|
| `/settings` | `Pengaturan Bisnis` | `Pengaturan Bisnis` _(keep)_ | `Pengaturan` |
| `/businesses/create` | `Tambah Bisnis` | `Tambah Bisnis` _(keep)_ | `Tambah Bisnis` |
| `/businesses/{id}/edit` | `Ubah Bisnis` | `Ubah Informasi Bisnis` _(keep)_ | `Ubah Bisnis` |

**3. Conditional visibility**
The `pos_transactions_enabled` checkbox will be visible only when `pos_enabled` is checked, via simple JS toggle. Avoids enabling transactions for businesses without POS active.

## Risks / Trade-offs

- [Low] Existing settings with `pos_enabled=true` still show `pos_transactions_enabled=false` → Users must manually opt-in. This is intentional.
