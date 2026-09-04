## Context

Supplier create/update has five write paths, each with different (and mostly missing) duplicate protection:

1. `Modules/People/Http/Controllers/SuppliersController@store` — admin form, has an inline closure-based `supplier_name` uniqueness check, but scoped to `setting_id` and exact-match (no case/whitespace normalization). No `contact_name` check.
2. `Modules/People/Http/Controllers/SuppliersController@update` — same pattern, excludes current record by id.
3. `app/Livewire/Modules/People/Modals/SupplierQuickAddModal.php` — Livewire quick-add used from Purchase create/edit, no uniqueness check at all.
4. `POST /api/suppliers` (routes/api.php closure) — used by the Alpine-driven quick-add modal (`create-alpine.blade.php`), no uniqueness check at all.
5. `PurchaseImportService::findOrCreateSupplier` and `ExpenseImportService::findOrCreateSupplier` — both do case-insensitive find-or-create matching, but scoped to `setting_id`, so the same real-world supplier gets a separate record per setting.

Separately, supplier *selection* is already inconsistent: `Supplier::find($id)` (used by `Purchase\CreateForm`/`EditForm` once an id is known) has no `setting_id` filter, so any supplier is selectable from any setting today. But `SupplierSearchDropdown::fetchSuppliers()`/`resolveLabel()` (the search/browse UI) still filters by `setting_id`, meaning a supplier created under one setting may not even appear in another setting's search results, despite being selectable if its id is somehow known. This is the same inconsistency `global-customer-identity` (archived change `2026-07-21-normalize-global-customer-name`) already resolved for customers.

Verified against production data: `supplier_name` has zero duplicate groups today, both within-setting and globally (212 suppliers across 7 settings). `contact_name` has one collision group of 14 — all sharing the literal string `"Imported Supplier"`, which is `ExpenseImportService`'s hardcoded placeholder for imports that don't supply a real contact name. This placeholder must change before a `contact_name` uniqueness rule can apply to import paths, or every import after the first would fail.

## Goals / Non-Goals

**Goals:**
- Suppliers are a single global identity: `setting_id` on `suppliers` is provenance-only (which setting originally created the record) and is never used to filter, scope, match, or restrict supplier search, matching, selection, or duplicate detection.
- Prevent new duplicate `supplier_name` values (case-insensitive, trimmed) at every one of the five create/update/import-matching entry points.
- Prevent new duplicate `contact_name` values (case-insensitive, trimmed, non-empty only) at the same entry points.
- Import matching (`findOrCreateSupplier` in both services) becomes global: an import that would previously create a duplicate per-setting supplier now matches the existing global record instead.

**Non-Goals:**
- No DB-level unique constraint/migration (data is already clean, but this stays consistent with the equivalent customer change's app-level-only approach).
- No retroactive merge, backfill, or rename of existing supplier records — including the 14 suppliers sharing the "Imported Supplier" contact_name placeholder; only future imports get the corrected (`null`) placeholder.
- No change to `Purchase.setting_id` or `Expense.setting_id` — those remain transaction-ownership fields, unrelated to and unaffected by the supplier record's own `setting_id` becoming provenance-only.
- No change to existing Purchase or Expense records or their supplier attributions.
- No changes to `Customer`/`customers` (already handled by `prevent-duplicate-customer-names`); this change reuses the same pattern for `Supplier` but does not touch the customer code.

## Decisions

**Reuse the shared uniqueness `Rule` pattern from `prevent-duplicate-customer-names`, generalized to any table/column.**
`Modules/People/Rules/UniqueCustomerField.php` already implements case-insensitive, trimmed, global uniqueness checking with an excludable id. Rather than write a near-duplicate `UniqueSupplierField`, generalize it (e.g. rename/parameterize to accept a table name, or extract a shared base) so both `customers` and `suppliers` uniqueness checks share one implementation. This avoids the same logic drifting between two classes over time.

**`findOrCreateSupplier` in both import services drops `setting_id` from its match query, but keeps writing `setting_id` on create (provenance).**
The match `WHERE` clause becomes `Supplier::whereRaw('LOWER(TRIM(supplier_name)) = ?', [$normalizedName])->first()` (no `setting_id` condition). The `Supplier::create([...])` call keeps `'setting_id' => $settingId` so the record still records which setting's import first created it — that's the provenance use, not a scoping use. This is a deliberate behavior change (flagged **BREAKING** in the proposal): future imports across different settings that reference the same supplier name will now resolve to one shared record instead of one-per-setting.

**`ExpenseImportService`'s `contact_name = 'Imported Supplier'` placeholder becomes `null`.**
This removes the only real collision risk contact_name uniqueness would hit today. `PurchaseImportService::findOrCreateSupplier` already accepts an optional `$contactName` parameter that can be genuinely null — no change needed there beyond the shared match-query fix.

**`SupplierSearchDropdown` drops its `setting_id` filter in `fetchSuppliers()`/`resolveLabel()`.**
This is a search/UX consistency fix, not a duplicate-prevention mechanism — it closes the gap where selection-by-id was already global but browse/search wasn't, which would otherwise leave a confusing half-global state after this change ships.

**No DB-level constraint**, for the same reasoning as `prevent-duplicate-customer-names`'s design: even though supplier data is clean today, mixing enforcement strategies between the two nearly-identical entities adds inconsistency without a clear benefit; a DB constraint can be added for both together later if desired.

## Risks / Trade-offs

- **[Risk] Import behavior change reattributes future purchases/expenses to a different supplier record than before**, if the same supplier name was previously imported under two different settings and treated as two separate suppliers. → **Mitigation**: this is the intended fix (duplicate suppliers were the bug), and no historical Purchase/Expense record's `supplier_id` is touched — only future import runs pick a different (now-shared) supplier record. Call out explicitly in the proposal as **BREAKING** matching behavior, not data mutation.
- **[Risk] Race condition**: two concurrent requests could both pass the uniqueness check before either commits. → **Mitigation**: accepted, same as the customer change — app-level checks reduce but don't eliminate this window; no DB constraint means it isn't fully closed. Documented as a known limitation.
- **[Risk] Legitimate same-named suppliers get blocked** (e.g. two different real businesses coincidentally sharing a name). → **Mitigation**: same trade-off already accepted for customers; no override/bypass flow added, consistent with existing phone/email/npwp-style checks having none either.
- **[Risk] Generalizing `UniqueCustomerField` into a shared rule touches code the customer change already shipped and archived.** → **Mitigation**: keep the generalization backward-compatible (same behavior for `customers` calls, e.g. via a table-name/model parameter with `customers` as an equivalent default), and add focused regression coverage on the customer uniqueness tests to confirm no behavior change there.

## Migration Plan

No database migration. Deploy is a standard code change:
1. Generalize (or duplicate, if generalizing proves riskier than the gain) the shared uniqueness `Rule` for use against `suppliers`.
2. Wire it into the five supplier write/match paths.
3. Drop `setting_id` scoping from `SupplierSearchDropdown` and both import services' match queries.
4. Fix `ExpenseImportService`'s `contact_name` placeholder to `null`.
5. No rollback complexity beyond reverting the code change — no data is touched or migrated.

## Open Questions

- Should a future change add DB-level unique constraints for both `customers` and `suppliers` together, now that both have (or will have) clean data? (Flagged as follow-up, not answered here.)
