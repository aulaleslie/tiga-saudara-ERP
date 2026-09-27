## Context

The product detail page currently renders an inline DELETE form for each bundle and calls the browser-native `confirm('Yakin ingin menghapus?')`. `ProductBundleController::destroy()` enforces delete permission, nested product ownership, and active-setting ownership, then deletes only the route bundle without an explicit transaction.

Future-created bundle copies now share a nullable `replica_group_uuid`, while historical rows remain null. Price editing already establishes the security pattern for optional cross-business intervention: display an opt-in only for grouped rows, derive targets from the persisted route model, and never trust client-supplied lineage. Bundle deletion should extend that pattern while applying stronger destructive-action presentation and atomicity.

## Goals / Non-Goals

**Goals:**

- Replace the native browser prompt with a clear Indonesian application modal.
- Preserve active-business-only deletion as the unchecked default.
- Allow explicit atomic deletion of every surviving copy from the same future creation group.
- Prevent forged or null lineage from widening deletion scope.
- Verify only touched deletion behavior and directly related Product Bundle regressions.

**Non-Goals:**

- Backfilling or inferring historical replica groups.
- Soft deletion, restore, archival, or audit-history functionality.
- Selecting individual target businesses.
- Creating or repairing missing bundle copies.
- Changing product deletion, bundle pricing, Sales, POS, or transaction snapshots.
- Running the full application test suite.

## Decisions

### Use one reusable Bootstrap deletion modal on the product detail page

Replace each inline `onclick="return confirm(...)"` with a non-submitting button that supplies the selected bundle's name, DELETE action URL, and grouped/legacy eligibility to one page-level Bootstrap modal. The modal owns the CSRF-protected method-spoofed DELETE form and populates it when opened.

Bootstrap matches the application's existing UI and avoids the inconsistent operating-system/browser dialog. SweetAlert was considered but rejected because a standard Bootstrap modal already fits the page's stack and supports richer conditional guidance without introducing another interaction pattern. Rendering one modal per bundle was rejected because duplicate markup and IDs complicate maintenance and accessibility.

The modal title is `Hapus Paket Penjualan`, its destructive button is `Hapus Paket`, and its secondary button is `Batal`. The body names the selected bundle and states that deletion cannot be undone.

### Reset modal state for every selected bundle

Each trigger must overwrite the form action and visible bundle name, reset the group checkbox to unchecked, and toggle grouped versus historical guidance from server-rendered trigger metadata. This prevents a prior selection or checked state from leaking into a later deletion.

Use escaped data attributes or equivalent safe serialization for names and route URLs. The server remains authoritative; UI metadata controls presentation only.

### Submit only a boolean cross-business intent

Name the checkbox/request field `delete_from_all_businesses` (with the conventional hidden `0` value and checkbox `1`). Validate it as nullable boolean. Do not render or accept a replica-group UUID as an actionable form value.

The phrase `Hapus paket ini dari semua bisnis` is explicit about the destructive scope. The checkbox remains unchecked by default because local deletion is the established behavior and accidental fan-out has high impact.

### Derive delete targets from the authorized route bundle

Retain the current permission, nested parent-product, and active-setting checks before starting destructive work. Within a database transaction:

- If cross-business deletion is not selected, delete only the route bundle.
- If selected and the route bundle's persisted `replica_group_uuid` is non-null, delete all existing headers carrying that UUID using an Eloquent query.
- If selected but the persisted UUID is null, fall back to deleting only the route bundle; never issue a `WHERE replica_group_uuid IS NULL` delete.

Delete targets are intentionally not additionally constrained by active setting, because the authorized opt-in represents a system-managed cross-business group action. A client-supplied lineage field is ignored by deriving scope only from route-bound state.

### Keep grouped deletion atomic

Wrap both local and grouped deletion paths in `DB::transaction()` or equivalent begin/commit/rollback handling. Deleting each header cascades to its component rows under the existing bundle-to-item foreign key. A failure during any group member deletion rolls back earlier member and component deletions.

A set-based Eloquent delete is efficient but may not dispatch per-model deletion events. Implementation should first inspect whether ProductBundle observers, media cleanup, or delete hooks exist. If lifecycle hooks are required, lock and iterate a deterministic ID-ordered target collection inside the transaction; otherwise a guarded set-based delete is acceptable. This decision must preserve existing model deletion semantics.

### Preserve partial-group behavior

Group deletion acts on existing rows only. It neither requires one member per setting nor reconstructs deleted members. This aligns with prior local deletion behavior and settings created after bundle creation.

## Risks / Trade-offs

- [A stale modal could submit the wrong bundle] → Reset action, name, eligibility, and checkbox on every trigger; cover switching between multiple bundles in focused UI tests.
- [Null lineage could delete every historical bundle] → Require a non-null persisted UUID before any group query and test multiple null-lineage rows.
- [Forged client lineage could target another group] → Never use request lineage; test with another real group's UUID.
- [Bulk deletion could bypass model lifecycle hooks] → Inspect existing model events/observers and choose deterministic per-model deletion when hooks exist.
- [A mid-group exception could leave partial deletion] → Execute the complete action in one transaction and force a failure after at least one deletion in a focused rollback test.
- [The checkbox could remain selected when switching modal targets] → Explicitly reset it to unchecked every time the modal opens.

## Migration Plan

No database migration is required. Deploy the modal, request handling, transactional deletion, and focused regression tests together. Rollback restores the prior local-only controller behavior and deletion control; existing bundle data requires no transformation.

## Open Questions

None. Cross-business deletion is opt-in, future-group-only, atomic, and presented through a Bootstrap modal.
