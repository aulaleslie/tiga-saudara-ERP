# Design

## Context

See `proposal.md` for motivation. The cross-business product price page currently renders `average_purchase_price` once per setting as a disabled field, while its combined save intentionally excludes that field. Live purchase approval calculates an average and then uses `ProductAveragePriceSynchronizer` to copy it to every setting. Sale and POS HPP capture reads the physical owner's setting row and persists immutable sale cost snapshots, so the storage remains per setting even though normal live values are globally synchronized.

The page already has permission enforcement, Indonesian currency formatting, optimistic row versions, transactional multi-row saves, a product-price feed recorder, and a general `Ubah` state. The new operation must not submit or disturb that broader form.

## Goals / Non-Goals

**Goals:**

- Provide one narrow correction path for the globally synchronized average purchase price.
- Make scope, future effect, historical-snapshot behavior, and later purchase recalculation explicit before persistence.
- Preserve per-setting storage and owner-aware HPP resolution while enforcing a single submitted value across current settings.
- Coordinate validation, locking, synchronization, and audit recording in one transaction.

**Non-Goals:**

- Moving average purchase price to a new global table or removing per-setting columns.
- Recalculating historical sale, bundle-component, return, or replacement HPP snapshots.
- Changing purchase receiving, purchase correction, normalization, import, or HPP fallback algorithms.
- Changing `last_purchase_price` behavior or the existing general cross-business price editor.
- Requiring a full application test-suite run.

## Decisions

### D1: Use a separate endpoint and form for global HPP

Add a dedicated update route/controller action and Form Request under the existing cross-business price-management permission. The modal submits only the replacement average plus signed or otherwise server-verifiable loaded-state evidence.

Rationale: this prevents unrelated base, conversion, or bundle values from being included and makes rollback and authorization boundaries easy to reason about.

Alternative considered: add the field to the existing combined save. Rejected because HPP has distinct warnings, validation, propagation, and concurrency semantics and could accidentally mix with unsaved general edits.

### D2: Keep the repeated read-only table column

Retain the current per-business average column so operators can see synchronization or legacy divergence after redirect. Add `Ubah HPP` beside `Ubah`; do not add another page section. Disable or otherwise block the HPP action during general edit mode, and do not let modal activity enable general fields.

Rationale: this matches the agreed compact UI and keeps row-level storage visible without falsely making each row independently editable.

Alternative considered: a permanent standalone HPP card. Rejected in favor of an on-demand dialog.

### D3: Treat the submitted value as a deliberate normalization command

Load all current setting IDs and all product price rows. The dialog derives a current display value from the active setting's row, falling back deterministically to an existing row or zero, and receives an explicit divergence flag when stored averages differ. On valid save, lock the relevant settings/price rows, revalidate membership, presence, versions, and average values against loaded-state evidence, create any missing per-setting price rows with unrelated fields at their established defaults, and set one positive average on every current setting.

Rationale: the database contract is per setting and downstream HPP resolution depends on those rows. One UI field therefore means one atomic command over multiple rows, not a schema collapse.

Alternative considered: update only existing rows. Rejected because a newly missing business row would immediately violate the promise that the value applies to every business.

### D4: Require a positive HPP for stock-managed products

Validate supported numeric precision and a value greater than zero, and reject the operation for non-stock-managed products.

Rationale: `AverageCostResolver` treats zero, negative, blank, and non-finite values as missing cost. Presenting zero as a successfully enabled HPP would be misleading; non-stock products intentionally snapshot zero through their separate classification.

Alternative considered: permit zero with an extra warning. Rejected because it creates a successful manual workflow that downstream logic immediately categorizes as missing HPP.

### D5: Record only changed rows under one operation group

Extend the existing product price feed contract to include manual average-price corrections. Capture per-setting before/after values, omit unchanged rows from qualifying change details, use one operation UUID, identify the authenticated actor/manual source, and keep feed persistence inside the same database transaction. Apply the same visibility boundary used for purchase-price information.

Rationale: a global correction needs an explainable audit trail without producing noise for no-op rows or leaking cost information.

Alternative considered: rely only on application logs. Rejected because the product price feed is the established operator-facing audit surface.

### D6: Redirect back to refreshed server state

After commit, redirect to the same product's cross-business price page with the agreed success message. On validation failure, return with the submitted input and reopen the modal; on stale-state conflict, return an actionable reload message and do not preserve stale authority.

Rationale: a redirect reloads every displayed business value from committed state and avoids client-side reconciliation across duplicated cells.

### D7: Verify the focused workflow only

Add focused feature tests around the dedicated request/service behavior plus focused view assertions for modal text and interaction hooks. Run those focused tests and relevant existing cross-business price tests; do not plan or require the full suite.

Rationale: the change is localized and the user explicitly requested proportionate focused verification.

## Risks / Trade-offs

- [A concurrent purchase approval changes HPP while the dialog is open] → Include all relevant averages and row versions in trusted loaded-state evidence, lock during revalidation, and reject stale submission.
- [A business is created or removed after page load] → Bind evidence to the complete setting membership and reject drift before writes.
- [Legacy values differ and the displayed current value appears authoritative] → Display an explicit divergence warning and state that the submitted replacement, not the displayed reference, becomes global.
- [A manually corrected HPP is later replaced unexpectedly] → Warn that approved purchase receiving can recalculate the value.
- [Audit visibility exposes sensitive cost] → Reuse purchase-price field authorization and server-side masking.
- [Creating missing price rows chooses defaults] → Reuse the existing `ProductPrice` creation conventions and preserve all existing rows' unrelated fields.

## Migration Plan

1. Deploy the route, request/service behavior, feed support, modal, and focused tests without a schema migration.
2. Existing values remain untouched until an authorized operator saves the dialog for a product.
3. A save over divergent legacy values normalizes only that selected product.
4. Rollback removes the action and endpoint; already synchronized values remain valid product-price data and require no data rollback.
