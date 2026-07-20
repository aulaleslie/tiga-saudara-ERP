## Context

The customer table predates `contact_name` and requires `customer_name`; `contact_name` was added later as a nullable field. Sales import follows that underlying schema contract: it resolves customers globally by `customer_name`, creates `customer_name`, and only sets `contact_name` when source contact data exists. The import path is correct and is a protected reference for this change.

Later UI fixes treated `contact_name` as the primary label and copied the only collected name into both columns. That workaround now causes three forms of drift: import-shaped records render blank in contact-only views, single-name records can render as `NAME - NAME`, and the full Customer form validates the optional contact more strongly than the canonical name. Most operational customer queries are already global, but Settings still scopes POS walk-in choices and validation by `setting_id`.

This is a cross-cutting normalization across People, Sales, POS, and Settings. The safest approach is additive at the read/presentation boundary and corrective for future writes, with no historical rewrite.

## Goals / Non-Goals

**Goals:**

- Make `customer_name` the required canonical identity for all non-import customer creation and editing paths.
- Keep `contact_name` optional from schema through validation and persistence.
- Make customer lists, loaders, search options, and ordinary customer labels use `customer_name`, with a narrow fallback for malformed historical rows.
- Keep customer visibility and selection global while allowing optional `setting_id` provenance on manual writes.
- Preserve existing customer records and protect sales import from modification.
- Reuse centralized model-level name resolution where a robust display fallback is needed.

**Non-Goals:**

- Changing sales-import parsing, matching, caching, or persistence.
- Removing `customers.setting_id`, changing its foreign key, or changing setting ownership on existing rows.
- Backfilling, clearing, merging, or deduplicating existing customer records.
- Changing sale, checkout, return, reporting, or inventory ownership rules.
- Removing `contact_name` or specialized views that deliberately show it as supplemental information.
- Reworking existing optional email/phone uniqueness constraints beyond preserving current valid handling.

## Decisions

### D1: Use `customer_name` as the canonical identity

All creation/edit validation and persistence SHALL treat trimmed `customer_name` as required. `contact_name` SHALL be independently nullable and SHALL never be populated from `customer_name` merely to satisfy a reader.

This follows the original non-null database column and the protected sales-import contract. The alternative—continuing to duplicate the value into `contact_name`—conceals incorrect readers, loses field meaning, and produces duplicate composite labels.

### D2: Correct readers without rewriting historical data

Customer-management lists and ordinary customer selectors/loaders SHALL use `customer_name` as their label and primary ordering/search field. Search MAY continue matching `contact_name` so users can find existing records by contact person. A centralized empty-safe resolver SHALL fall back to `contact_name` only when an anomalous historical row has a blank canonical name, and SHALL not repeat identical values.

Existing receipt behavior that intentionally presents supplemental contact/company context can continue using the model display accessor, provided the accessor remains empty-safe and duplicate-safe. This preserves useful, already-specified receipt context without allowing `contact_name` to become the canonical identity again.

The alternative—a data backfill from `contact_name`—would mutate user data, cannot reliably distinguish company/customer identity from a contact person, and is unnecessary to fix the defect.

### D3: Normalize existing entry points rather than introduce a new persistence service

The Customer controller, Sales create/edit shared quick-add modal, legacy quick-add component, and POS endpoint SHALL receive focused mapping and validation corrections. They SHALL keep their existing routes, events, response shapes, permissions, idempotency behavior, and surrounding optional-field handling.

A new shared creation service could reduce duplication, but introducing it in the same change would enlarge the regression surface. Centralizing name presentation on the Customer model provides the useful reuse while keeping persistence edits local and reviewable.

### D4: Keep `setting_id` as provenance, not a customer access boundary

Manual creation paths MAY continue storing the active `setting_id`. Queries that load, validate, search, or select a customer by identity SHALL not require that value to equal the active/source setting. Settings walk-in customer options and validation SHALL accept any existing global customer ID, matching current Sales/POS lookup and split-checkout resolution behavior.

The alternative—removing `setting_id` from writes or schema—would require a migration and could affect auditing or existing uniqueness constraints without providing value for this defect.

### D5: No sales-import edits and no customer-table migration

Implementation tasks and review SHALL explicitly exclude `Modules/Sale/Services/SalesImportService.php`. Regression coverage SHALL create an import-shaped customer record (`customer_name` populated, `contact_name` null) and prove all relevant readers work with that shape.

Because `contact_name` is already nullable and `customer_name` is already the original required column, no schema migration is necessary. Application validation is the source of the remaining incorrect requirement.

## Risks / Trade-offs

- [Historical rows may have blank `customer_name` and only `contact_name`] → Retain a read-time fallback without treating it as the normal write contract; do not guess at a destructive backfill.
- [Existing tests encode the old duplicate-field workaround] → Replace those expectations with canonical-name and nullable-contact assertions, and retain explicit coverage that the customer list is nonblank.
- [Changing dropdown labels can affect Livewire event consumers] → Preserve IDs, event names, payload keys, and endpoint response keys; change only name values and normalization semantics.
- [Global walk-in selection broadens the Settings option list] → Continue validating that the customer ID exists, but remove only the setting predicate, consistent with existing global POS resolution requirements.
- [Specialized receipt labels could lose useful contact context] → Keep specialized composite presentation, but make it duplicate-safe and ensure canonical identity remains `customer_name`.
- [Placeholder contact values already stored remain present] → Preserve them; duplicate-safe display avoids repeated output and future writes stop creating more.

## Migration Plan

1. Add regression tests that capture the import-shaped record, canonical writes, nullable contact, global selection, and payload compatibility.
2. Update customer presentation/loaders and model resolution.
3. Update each non-import creation/edit path without changing routes or events.
4. Remove the remaining setting predicate from POS walk-in configuration options and validation.
5. Run focused People, Sales, POS, and Settings tests, followed by the project’s SQLite verification suite when feasible.
6. Confirm `SalesImportService.php` has no diff before release.

Rollback consists of reverting application and test changes. There is no database rollback or data restoration step because the change introduces no migration or backfill.

## Open Questions

None. The canonical field, nullable-contact rule, global selection behavior, protected import boundary, and no-migration approach are resolved.
