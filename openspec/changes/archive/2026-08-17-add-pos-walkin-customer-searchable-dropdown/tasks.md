## 1. Backend search endpoint

- [x] 1.1 Add `GET /settings/customers/search` route in `Modules/Setting/Routes/web.php`, inside the existing `['auth', 'role.setting']` group, named `settings.customers.search`.
- [x] 1.2 Add `SettingController::customerSearch(Request $request, PosCustomerSearchService $searchService)` mirroring `PosSellController::customerSearch`'s validation (`q` required string, `limit` optional 1-20), gated by `Gate::denies('settings.access')` (matching `index()`'s existing authorization check).
- [x] 1.3 Delegate to `PosCustomerSearchService::search()` and return its payload as JSON, unchanged.

## 2. Controller: stop preloading all customers

- [x] 2.1 In `SettingController::index()`, remove the `Customer::query()->orderBy(...)->get(...)` full-list fetch.
- [x] 2.2 Replace it with a lookup of only the currently selected customer: if `$settings->pos_walk_in_customer_id` is set, fetch that single `Customer` (`id, customer_name, contact_name, customer_phone`); otherwise null.
- [x] 2.3 Pass the single selected customer (or null) to the view instead of `$walkInCustomerOptions`.

## 3. Frontend: Select2 AJAX dropdown

- [x] 3.1 In `Modules/Setting/Resources/views/index.blade.php`, replace the `<select id="pos_walk_in_customer_id">`'s full `@foreach` option list with just the static `"Belum diatur"` empty option plus (if present) the pre-selected customer's `<option selected>`.
- [x] 3.2 Initialize Select2 on `#pos_walk_in_customer_id` with `ajax` pointed at `route('settings.customers.search')`, `data: term => ({q: term})`, a short `delay` (e.g. 250ms), and `processResults` mapping `response.results[]` (`id`, `display_name`, `customer_phone`) into `{id, text}` pairs consistent with the current server-rendered label format (`display_name` + optional `(phone)`).
- [x] 3.3 Set `allowClear: true` (or equivalent) so the empty "Belum diatur" state remains selectable, matching current behavior.
- [x] 3.4 Verify placement/initialization follows the same pattern as `resources/views/livewire/business-selector.blade.php` (existing Select2 AJAX example) for styling/behavior consistency.

## 4. Tests

- [x] 4.1 Add/update a feature test (see `Modules/Setting/Tests/Feature/SettingsWalkInCustomerMappingTest.php`) asserting the Settings index page no longer queries/renders the full customer list.
- [x] 4.2 Add a feature test for `settings.customers.search`: returns matching customers by name/contact/phone, respects `settings.access` authorization (403 for unauthorized users), and succeeds even when the current business has `pos_enabled = false`.
- [x] 4.3 Add a feature test confirming a user with `settings.access` but without `pos.access`/`pos.returns.view` can still search successfully via the new endpoint (distinguishing it from the POS route's gating).
- [x] 4.4 Confirm existing save/submit behavior for `pos_walk_in_customer_id` (including clearing to null) still passes.

## 5. Verification

- [x] 5.1 Run `composer test:fresh-sqlite` (or `php artisan test --filter=Setting`) to confirm all Setting module tests pass.
- [x] 5.2 Manually load `/settings`, search for a customer by partial name/phone, select one, save, and reload to confirm the selection persists and pre-renders correctly.
