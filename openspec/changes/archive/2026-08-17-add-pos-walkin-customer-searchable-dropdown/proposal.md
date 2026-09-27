## Why

The Business Settings page (`/settings`) renders the "Pelanggan Walk-In POS" field as a plain `<select>` populated with every `Customer` row in the database. As the customer list grows, this becomes slow to render and hard to use — admins have to scroll through hundreds/thousands of options to find the right one. It needs to become a searchable, AJAX-backed dropdown instead of a full preload.

## What Changes

- Replace the full customer preload in `SettingController@index` with a minimal lookup: only the currently-selected walk-in customer (if any) is fetched, to pre-render it as the selected option.
- Add a new Setting-scoped search route/controller method (`settings.customers.search`), gated by the same middleware as the rest of the Settings page (`auth`, `role.setting`, `can:settings.access`), so the dropdown works regardless of whether POS is enabled for the current business or whether the acting user holds POS permissions.
- The new endpoint delegates to the existing `PosCustomerSearchService` (unchanged) to avoid duplicating search logic — customers are treated as global master data and are not scoped by `setting_id`, matching existing behavior.
- Convert the "Pelanggan Walk-In POS" `<select>` in `Modules/Setting/Resources/views/index.blade.php` into a Select2 AJAX-powered searchable dropdown (Select2 is already loaded globally via `main-js.blade.php`), querying the new endpoint as the user types.

## Capabilities

### New Capabilities
- `settings-walk-in-customer-search`: Searchable, AJAX-backed customer lookup for the Business Settings "Pelanggan Walk-In POS" field, independent of POS enablement/permissions.

### Modified Capabilities
(none — no existing spec covers this field's current behavior)

## Impact

- `Modules/Setting/Http/Controllers/SettingController.php` — `index()` no longer preloads all customers; new `customerSearch()` method added.
- `Modules/Setting/Routes/web.php` — new `GET /settings/customers/search` route.
- `Modules/Setting/Resources/views/index.blade.php` — walk-in customer `<select>` becomes Select2 AJAX-driven.
- `Modules/Pos/Services/PosCustomerSearchService.php` — reused as-is (no changes expected).
- No database/migration changes.
