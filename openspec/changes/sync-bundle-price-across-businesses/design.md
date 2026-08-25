## Context

`ProductBundleController::store()` currently creates one independent `product_bundles` row for every existing `settings` row in one transaction. Those copies share submitted definition values but have unrelated primary keys and no durable creation lineage. `update()` authorizes the nested product and active setting, updates one header, then replaces only that copy's components. The edit page exposes `Harga Jual Paket` but has no cross-business action.

The earlier bundle-setting design intentionally deferred replica grouping and coordinated edits. This change introduces the smallest such coordination: future creations receive durable lineage, and an administrator may opt into synchronizing only the final bundle sale price. Existing data must not be guessed or backfilled.

## Goals / Non-Goals

**Goals:**

- Reliably identify setting-specific bundle copies produced by the same future creation operation.
- Let an authorized administrator explicitly apply one edited bundle sale price to all existing copies in that group.
- Preserve active-setting authorization and setting-local maintenance of every other bundle field.
- Keep creation and synchronized updates atomic and testable on MySQL/MariaDB and SQLite.

**Non-Goals:**

- Backfilling or heuristically matching historical bundle copies.
- Synchronizing names, descriptions, dates, enabled state, components, or informational component prices.
- Creating bundle copies for settings added after the original creation.
- Adding a business selector, preview, background job, API, or bulk repair workflow.
- Changing Sales or POS runtime price resolution or historical transaction snapshots.

## Decisions

### Store a nullable UUID replica-group identity on each bundle header

Add a nullable UUID-compatible `replica_group_uuid` column to `product_bundles` and index it for propagation lookup. Generate one UUID before the creation loop and persist it on every copy produced by that operation. The column stays nullable so deployment does not rewrite old rows; all newly created bundle groups must receive a value.

A UUID is stable across settings and independent of database insertion order. Matching on timestamps, name, parent product, or composition was rejected because those values can collide or drift. A separate replica-group table was rejected because the group currently owns no metadata or lifecycle beyond identity.

### Treat sale-price synchronization as a per-save opt-in

Add a boolean request field such as `apply_price_to_all_businesses`. Render it beside/below `Harga Jual Paket` with the Indonesian label `Terapkan harga ke semua bisnis`, unchecked by default. Use Laravel boolean normalization and preserve `old()` state after validation failure.

Making synchronization sticky was rejected because an administrator could unintentionally propagate a later local adjustment. Adding the control to create was rejected because creation already writes the submitted bundle sale price to every initial copy.

### Do not expose actionable synchronization for ungrouped rows

For a null `replica_group_uuid`, omit or disable the checkbox and show Indonesian guidance such as `Bundle lama tidak terhubung dengan salinan bisnis lainnya.` The server must also treat synchronization requested against an ungrouped route bundle as invalid or local-only without broad matching; it must never query `WHERE replica_group_uuid IS NULL` as a group.

Disabling synchronization is safer than guessing lineage from old data and satisfies the future-only requirement.

### Derive targets from the authorized route bundle inside the existing transaction

Keep the current product/setting route-ownership check. Validate the boolean request, begin the transaction, update the active-setting header and its component snapshots as today, and—only when opted in and the persisted group UUID is non-null—issue a set-based `bundle_sale_price` update for that UUID. Do not accept a group UUID from the form.

The propagated query intentionally includes the active copy; assigning the same price twice is harmless and keeps the target rule simple. It updates only `bundle_sale_price` (plus framework-managed `updated_at` if Eloquent's query builder is used). The active copy alone receives all other submitted header and item changes.

Using the existing transaction keeps delete-and-recreate component behavior and group price propagation all-or-nothing. A queued fan-out was rejected because it would introduce temporary divergence and failure recovery for a small, infrequent administrative operation.

### Keep deletion local and tolerate missing group members

Deleting one setting copy remains local and does not remove the lineage or other copies. Future synchronization updates whatever members still exist. A setting created later receives no member automatically.

Requiring a complete one-copy-per-current-setting group was rejected because local deletion is existing supported behavior and the requested operation concerns the same surviving creation group, not group repair.

## Risks / Trade-offs

- [Concurrent local and synchronized edits remain last-write-wins] → Preserve the project's existing small-administrator concurrency model and cover transactional outcomes; optimistic locking remains out of scope.
- [A malformed implementation could update every null legacy row] → Guard explicitly for a non-null persisted UUID before any propagation query and test multiple ungrouped records.
- [Group UUIDs could accidentally be regenerated during edit] → Assign lineage only in creation and never include it in editable request fields.
- [A synchronized price may not suit a business-specific context] → Keep the checkbox explicit, unchecked by default, and propagate no other configuration.
- [Partial group membership after deletion can surprise administrators] → Define synchronization as applying to existing group members only and retain local deletion semantics.

## Migration Plan

1. Add nullable, indexed `replica_group_uuid` storage without a data update.
2. Deploy creation logic that assigns one UUID to all newly created copies.
3. Deploy the edit control and transactional propagation behavior with focused feature tests.
4. Verify focused Product Bundle tests, then run the project's preferred broader Laravel test command as proportionate.

Rollback removes the UI and propagation logic first, then drops the index and column. Bundle records and prices remain valid setting-local data; no reverse data transformation is required.

## Open Questions

None. The requested label, future-only lineage, price-only propagation, and treatment of settings created later are defined by this change.
