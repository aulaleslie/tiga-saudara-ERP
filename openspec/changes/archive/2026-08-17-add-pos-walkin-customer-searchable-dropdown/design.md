## Context

The Business Settings page (`Modules/Setting/Resources/views/index.blade.php`) has a "Pelanggan Walk-In POS" dropdown, backed by `SettingController@index` doing `Customer::query()->orderBy(...)->get(...)` — an unbounded, unpaginated fetch of every customer row rendered as `<option>` tags. This does not scale as the customer table grows.

An equivalent search already exists in `Modules/Pos/Services/PosCustomerSearchService`, used by POS's own customer picker (`pos.sell.customers.search` route). That route, however, sits behind `pos.enabled` (business-level toggle) and `can:pos.access` / `can:pos.returns.view` middleware — none of which are guaranteed for a user who can reach `/settings` via `settings.access`. Reusing that route directly would make the walk-in customer field unusable on businesses where POS isn't enabled yet, which is precisely a state this setting needs to support (configuring it *before* turning POS on).

Customers are confirmed to be global master data, not scoped by `setting_id` — this matches the existing (unscoped) behavior of both `SettingController@index` and `PosCustomerSearchService`, so no tenant-scoping change is needed here.

## Goals / Non-Goals

**Goals:**
- Replace the full customer preload with an on-demand, searchable Select2 dropdown.
- Keep the search endpoint reachable by anyone who can access `/settings`, independent of POS state/permissions.
- Reuse existing search logic (`PosCustomerSearchService`) rather than duplicating it.

**Non-Goals:**
- Changing `PosCustomerSearchService`'s query logic or its existing POS route/consumers.
- Scoping `Customer` records by `setting_id` (explicitly out of scope — customers are global).
- Any change to how the walk-in customer is used at POS checkout time (`PosCheckoutGroupCustomerResolverService` etc.) — only the settings admin UI changes.

## Decisions

**1. New Setting-scoped route instead of reusing the POS route directly.**
Add `GET /settings/customers/search` in `Modules/Setting/Routes/web.php`, inside the existing `['auth', 'role.setting']` group (matching the rest of `/settings`), with an additional `can:settings.access` (or `settings.edit`, matching whatever currently guards this form) check. The controller method delegates straight to `PosCustomerSearchService::search()` — no logic duplication, just a differently-gated entry point.
*Alternative considered*: point Select2 at the existing `pos.sell.customers.search` route. Rejected because it would 403/redirect for settings admins on businesses without POS enabled, or without POS permissions — exactly the scenario this field exists to support.

**2. Controller no longer preloads the full customer list.**
`SettingController@index` only needs to resolve the single currently-selected `pos_walk_in_customer_id` (if set) to pre-render it as the selected `<option>` for Select2's initial state. Everything else is fetched on search.

**3. Select2 for the frontend widget.**
`select2.min.js` is already loaded globally via `resources/views/includes/main-js.blade.php`, and the codebase has existing Select2 AJAX patterns (`business-selector.blade.php`, `business-source-selector.blade.php`) to follow for consistency. No new frontend dependency needed.

**4. Response shape.**
`PosCustomerSearchService::search()` returns `{query, results: [{id, customer_name, contact_name, customer_phone, display_name}], meta}`. The new controller method returns this as-is; the Select2 `ajax.processResults` callback on the frontend maps `results[]` into `{id, text}` pairs (using `display_name`, with phone appended similar to the current server-rendered format), rather than adding a second response format server-side.

## Risks / Trade-offs

- [Duplicate-looking routes for customer search (`pos.sell.customers.search` vs `settings.customers.search`)] → Both are thin wrappers around the same `PosCustomerSearchService`; acceptable given they have genuinely different authorization boundaries. Documented in code/tests so future readers understand why two routes exist.
- [Select2 initial state must pre-render the selected customer as an `<option>` for edit-mode to display correctly] → Handled by fetching just that one customer server-side (cheap, single-row lookup) instead of the full list.
- [Empty/no-selection state ("Belum diatur")] → Preserve the existing empty `<option value="">Belum diatur</option>` as a non-AJAX static first option.

## Migration Plan

No data migration. Deploy is a standard code change (route + controller method + view/JS update). Rollback is a plain revert — no schema or persisted-data impact.
