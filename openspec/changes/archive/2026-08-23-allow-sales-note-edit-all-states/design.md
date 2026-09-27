## Context

The setting-scoped sales detail page currently renders `sales.note` as static text. Broader sale editing is lifecycle-sensitive: approved sales require additional authority, while dispatched sales use a restricted monetary-edit path to protect sale-detail, dispatch, serial, stock, and cost relationships. Those protections unnecessarily prevent a harmless note-only correction.

The purchase detail page already uses `App\Livewire\Purchase\PurchaseNoteEditor` as the established brownfield pattern. It loads archived records explicitly, enforces the active setting, requires the ordinary update permission, blocks archived records, validates `nullable|string|max:1000`, normalizes an empty string, and changes only `note`. Sales can reuse that interaction and security shape without invoking either full sale editing or monetary editing.

## Goals / Non-Goals

**Goals:**

- Provide status-independent note editing on the normal setting-scoped sales detail page.
- Reuse `sales.edit` and enforce authorization at component mount and mutation time.
- Keep the operation constrained to the existing `sales.note` column.
- Match the established purchase editor interaction and validation behavior.
- Preserve archive and tenant/setting isolation.

**Non-Goals:**

- Changing full-sale, approved-sale, or dispatched monetary-edit permissions.
- Editing any sale details, prices, totals, customer data, lifecycle state, dispatch data, payments, returns, serials, stock, or cost snapshots.
- Adding a new permission, route, database column, migration, or audit subsystem.
- Enabling note editing from global or cross-business sales views.

## Decisions

### Use a narrow Livewire component modeled on the purchase note editor

Create a sales-specific Livewire component and view rather than routing the operation through `SaleController::update()` or `SaleService::updateSale()`. The component will load the sale with archived records, verify setting ownership, expose read and edit states, validate input, and issue an Eloquent update containing only `note`.

This avoids the full sale update path, which can rebuild details and is intentionally unavailable in later lifecycle states. Reusing that path or adding status exceptions to it would expand the mutation surface and risk dispatch, stock, serial, bundle, and cost lineage.

### Authorize with `sales.edit` regardless of lifecycle status

The ordinary sales edit permission is the direct counterpart to purchase's `purchases.update` permission and clearly represents the privilege requested for note maintenance. The component will not call `Sale::resolveEditMode()` and will not require `sales.approved.edit` or `sales.dispatched.monetary.edit`, because those permissions govern broader lifecycle-sensitive mutations.

A new `sales.notes.edit` permission was considered but rejected: it would differ from the purchase behavior, require permission seeding and role migration, and introduce operational overhead not requested by the feature.

### Reauthorize against fresh state for every mutation

Mount determines whether edit controls are shown, but `startEditing()` and `save()` will reload the sale with archived records and repeat the permission and archive checks. Sale lookup for cancel and save will repeat active-setting validation. This prevents stale component state from bypassing a permission revocation, archive operation, or setting boundary.

### Expose the component only on the normal sales detail view

Replace the static note block on `sale::show` with the Livewire component. Global payment and cross-business detail surfaces will retain static note rendering. This matches the existing purchase distinction between setting-scoped maintenance and global read-only inspection.

### Match purchase validation and interaction semantics

Use `nullable|string|max:1000`, convert an empty string to `null`, restore the database value on cancel, and dispatch a localized success notification after saving. Users who can view but cannot edit will see the note without an edit action.

## Risks / Trade-offs

- [A note can change after approval, dispatch, or return and therefore no longer match an earlier printout] → Treat notes as operational annotations, constrain changes to authorized users, and leave financial and inventory data immutable through this path.
- [A Livewire component can retain stale authorization or archive state] → Reload and reauthorize the sale for every edit/save mutation instead of trusting mount-time flags.
- [A broad model update could accidentally persist unrelated public component state] → Pass an explicit `['note' => $normalizedValue]` update payload only.
- [Behavior can drift from the purchase implementation] → Mirror its component structure and add parity-focused tests for validation, archive handling, setting isolation, cancel, and empty-note normalization.

## Migration Plan

1. Add the sales Livewire component and view.
2. Embed it in the normal setting-scoped sales detail page.
3. Add focused Livewire and detail-view tests across every sale lifecycle state and denial boundary.
4. Deploy without a database or permission migration.

Rollback consists of restoring static note rendering and removing the sales-specific component and tests; stored note values remain compatible.

## Open Questions

None. Permission, lifecycle coverage, archive behavior, setting scope, and global-view behavior are defined.
