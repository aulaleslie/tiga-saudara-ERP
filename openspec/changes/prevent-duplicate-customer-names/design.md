## Context

Customer create/update has five independent write paths, none of which check for duplicate `customer_name` or `contact_name`:

1. `Modules/People/Http/Controllers/CustomersController@store` — admin form, has inline closure-based uniqueness checks for `customer_phone`, `customer_email`, `identity_number`, `npwp` (scoped to `setting_id`), but not for either name field.
2. `Modules/People/Http/Controllers/CustomersController@update` — same pattern, excludes current record by id.
3. `app/Livewire/Modules/People/Modals/CustomerQuickAddModal.php` — Livewire quick-add used from Sale/other flows, trims `customer_name`/`contact_name` but has no uniqueness rule.
4. `app/Livewire/Customer/CreateModal.php` — separate Livewire quick-add modal, same gap.
5. `Modules/Pos/Http/Controllers/PosSellController@customerStore` — POS quick-create, validates only `customer_name` required/string/max:255, no uniqueness, and always stores `contact_name = null`.

Per the prior `global-customer-identity` spec (archived change `2026-07-21-normalize-global-customer-name`), customers are a global identity: visible, searchable, and selectable across all `setting_id` values, not scoped per tenant. Duplicate checks must therefore be global too — otherwise two settings could each create their own "Toko ABC" and defeat the point of global customer resolution.

Production data already contains 42 case-insensitive `customer_name` collisions (verified via direct query against `customers`). `contact_name` currently has zero collisions. A DB-level `UNIQUE` constraint on `customer_name` cannot be added without first resolving those 42 groups (merge or rename), which requires business judgement per case and is out of scope for this change.

## Goals / Non-Goals

**Goals:**
- Prevent new duplicate `customer_name` values (case-insensitive, trimmed) at every create/update entry point.
- Prevent new duplicate `contact_name` values (case-insensitive, trimmed) at every create/update entry point, when `contact_name` is non-empty.
- Keep the check global (not scoped by `setting_id`), consistent with `global-customer-identity`.
- Correct the stale `customer-creation-field-consistency` spec so it no longer contradicts current, intended POS behavior.

**Non-Goals:**
- No DB-level unique constraint/migration in this change (blocked by 42 existing collisions).
- No retroactive cleanup, merge, or rename of existing duplicate customers.
- No change to `display_name`/`canonical_name` presentation logic — already correct.
- No change to search/selector behavior (`PosCustomerSearchService`, `CustomerLoader`, etc.) beyond what's needed to query for duplicates.

## Decisions

**Validation lives at the application layer, not the database.**
Alternative considered: add a DB unique index on `LOWER(TRIM(customer_name))` (via a generated/computed column or functional index, since MySQL/older engines don't support expression indexes as cleanly as Postgres). Rejected for this change because 42 existing rows already collide — the migration would fail outright, and resolving those collisions is a data-governance decision, not a validation-code decision. Application-level validation stops new duplicates immediately without blocking on that cleanup. The DB constraint is flagged as a natural follow-up once cleanup happens.

**Uniqueness check implemented as a shared helper, not duplicated inline five times.**
Each existing write path currently repeats near-identical closure-based `DB::table('customers')->where(...)->exists()` checks for other fields (phone/email/identity/npwp). Rather than copy that pattern a sixth time in five places, add one small reusable check (e.g. a static helper on `Customer` or a small validation rule class, matching whichever pattern is more idiomatic to this codebase — a Laravel custom `Rule` class implementing `Illuminate\Contracts\Validation\Rule` is the cleanest fit since both Livewire `validate()` calls and controller `Request::validate()` calls can use it identically). Comparison uses `LOWER(TRIM(customer_name))` (or `LOWER(TRIM(contact_name))`) equality against the incoming trimmed/lowercased value, excluding the current record's id on update.

**`contact_name` uniqueness only applies when non-empty.**
Empty/null `contact_name` is common and intentional (POS-created customers, walk-in customer). The uniqueness rule must not fire on two customers both having a blank `contact_name`.

**POS behavior is not changed; the older spec is corrected instead.**
`customer-creation-field-consistency` currently requires POS to write `contact_name = customer_name`. Current code does not do this, and the newer `global-customer-identity` spec treats `contact_name` as optional supplemental data — consistent with leaving it null from POS. Changing POS code to match the older spec was considered but rejected: it would force every walk-in/POS-created customer to have a non-empty `contact_name`, which increases (not decreases) surface area for `contact_name` collisions with no compensating benefit, since `customer_name` is already canonical for display and matching.

## Risks / Trade-offs

- **[Risk] Race condition**: two concurrent requests could both pass the uniqueness check before either commits, resulting in a duplicate anyway (no DB constraint backstops it). → **Mitigation**: acceptable for this change given the existing data already has collisions and the requirement is "prevent new duplicates in the normal case," not "guarantee atomicity." Documented as a known limitation; DB constraint follow-up would close this gap.
- **[Risk] False positives from legitimate same-named entities** (e.g. two different real-world contacts who happen to share a common name like "Budi") → **Mitigation**: this is an inherent trade-off the user has already accepted by requesting duplicate prevention; error messaging should be clear enough that a legitimately-different customer can still be distinguished/created by an admin who confirms it's not a dup (out of scope: no override/bypass flow is being added, matching how existing phone/email/npwp checks work today with no override).
- **[Risk] Five separate write paths drift again in the future** if a sixth entry point is added without using the shared rule. → **Mitigation**: centralizing in one reusable `Rule` class makes it the path of least resistance for future write paths to pick up the same validation.

## Migration Plan

No database migration. Deploy is a standard code change:
1. Add the shared uniqueness `Rule` (or equivalent helper).
2. Wire it into the five write paths' existing validation arrays.
3. Correct the `customer-creation-field-consistency` spec text.
4. No rollback complexity beyond reverting the code change — no data is touched.

## Open Questions

- Should a future change add the DB-level constraint after a manual data-cleanup pass on the 42 existing `customer_name` collisions? (Flagged as follow-up, not answered here.)
