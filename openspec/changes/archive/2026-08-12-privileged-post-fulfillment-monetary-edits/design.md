## Context

Purchase documents become operationally linked to `received_note_details` once goods are received; the current normal purchase update deletes all `purchase_details` and recreates them, which would break those links through cascade deletion. Sales dispatch and bundle records similarly depend on the original sale/document-line identity, while the normal sale update deletes lines and regenerates cost snapshots.

The application already provides a dedicated received-purchase correction workflow under `purchases.received.correct`. That workflow has a correction audit, active-payment reconciliation, and an optional explicit cost-recalculation/replay path. It remains a separate, unchanged workflow. This change addresses the owner-facing ordinary edit experience for monetary invoice adjustments after fulfillment, without new document fields or an override data model.

## Goals / Non-Goals

**Goals:**

- Let authorized users use the familiar existing purchase/sale edit interface for approved documents and, after fulfillment, for a restricted monetary-only edit.
- Retain full pre-fulfillment editing, including quantity changes, for approved documents that have not been received or dispatched.
- Persist post-fulfillment monetary edits directly to the existing header and detail rows without replacing their identities.
- Preserve receipt, dispatch, stock, serial, bundle, product-price, and HPP-snapshot facts.
- Keep the existing received-purchase correction path and all of its behavior untouched.

**Non-Goals:**

- Adding amendment/override columns or a new reporting projection.
- Replacing or merging the received-purchase correction workflow.
- Allowing post-fulfillment changes to quantities, products, rows, bundles, locations, serials, counterparties, dates, references, payment records, or stock movements.
- Updating `product_prices`, `average_purchase_price`, `last_purchase_price`, or sales cost snapshots as a side effect.

## Decisions

### Use lifecycle-specific permissions with the existing edit permissions as prerequisites

The catalog will define `purchases.approved.edit`, `purchases.received.monetary.edit`, and `sales.dispatched.monetary.edit`; `sales.approved.edit` remains the approved-sale permission. A user must retain the ordinary `purchases.update` or `sales.edit` permission as applicable, plus the lifecycle-specific permission, to access the exceptional state.

**Rationale:** The existing approved-sale pattern already separates ordinary edit access from approved-document authority. Separate post-fulfillment permissions let an owner delegate invoice correction without granting broader correction, receiving, dispatch, or price-administration authority.

**Alternative considered:** Reuse `purchases.received.correct` for ordinary purchase editing and a generic sales equivalent. Rejected because the existing correction has a deliberate audit/payment/replay contract and must remain unaffected.

### Reuse the existing edit UI with a server-authoritative monetary-only mode

The current purchase and sale edit components will derive an edit mode from the persisted document lifecycle and current user's permissions. Approved, unfulfilled documents use their normal controls. Fulfilled documents expose only supported money inputs; controls for quantity, product selection, add row, remove row, bundle structure, counterparty, date, reference, payment method, and business selection are disabled or hidden.

The HTTP/Livewire submission path will independently derive the mode from the database record, reject forbidden request/cart changes, and never trust client-side disabled controls as authorization.

**Rationale:** It satisfies the requested familiar form experience without making client-side field locks a security boundary.

**Alternative considered:** Build a new correction page. Rejected because it duplicates the existing form and asks users to learn a separate workflow for a narrow change.

### Persist fulfilled-document monetary changes in place

The restricted save branch will lock the purchase/sale header and its existing detail rows; require an exact one-to-one mapping to the existing rows; validate that all protected values remain equal; normalize only supported monetary inputs; and update the matched rows by their existing primary keys. It will not call the current delete-and-recreate paths.

The allowed monetary inputs are line unit price/final line total, line discount, line tax selection/rate where normal document rules allow it, header tax/discount/shipping, and the derived monetary header/detail totals. Payment records and their amounts are not editable through this mode. The document's paid/due/payment-status summaries must remain consistent with the existing persisted active-payment state and must not mutate payment rows.

**Rationale:** Row identity is the essential boundary that retains links to receipt or dispatch history. Existing normalizers provide established tax, discount, and rounding semantics while scoped persistence avoids operational side effects.

**Alternative considered:** Permit normal form submission and verify only that quantities match. Rejected because its persistence code deletes and recreates protected rows and the sale path regenerates HPP snapshots even when quantities are unchanged.

### Explicitly avoid inventory-price and sales-cost services

The restricted save branch must not invoke receiving/dispatch execution, product stock updates, `ProductPrice` updates, purchase-cost recalculation, historical replay, or `SalesCostSnapshotService`. Existing correction replay stays available only via its current explicit action.

**Rationale:** A late supplier invoice amount changes the document's commercial value but must not silently rewrite current purchase-price indicators or historical profitability snapshots.

## Risks / Trade-offs

- [A crafted request bypasses the UI locks] → Load and lock the persisted document at save time, then validate every protected header and line field server-side before writing.
- [Normalizers alter fields outside the monetary scope] → Exercise the restricted branch with an explicit allowed-field whitelist and regression tests for protected values and row IDs.
- [Document total conflicts with active payment amounts] → Derive summary fields from existing active payments or reject an unsupported inconsistent result; never adjust payment rows in this workflow.
- [A monetary edit is confused with the existing purchase correction] → Keep entry points, permissions, audit/correction records, payment reconciliation, and replay actions distinct; label the form mode clearly.
- [Historically calculated reports read changed document prices] → This is intentional for document/reporting values; product price and sale HPP snapshot reports remain protected from automatic recomputation.

## Migration Plan

1. Add canonical permission keys and synchronize them through the existing permission seeding/sync mechanism without removing the correction permission.
2. Add lifecycle authorization and restricted UI state behind the new permissions.
3. Add the in-place restricted persistence branches and focused regression tests before exposing actions to roles.
4. Deploy with no schema migration and no historical data rewrite.
5. Rollback removes access to the new paths; already saved direct document monetary edits remain normal document data and require a subsequent authorized edit to reverse.

## Open Questions

- Confirm whether a mandatory correction reason is desired for the new ordinary monetary-only edit path. The existing correction workflow already requires one; this change does not require a new audit table.
- Confirm whether the edit screen should visually distinguish the restricted mode from the existing received-purchase correction action beyond disabled monetary/non-monetary controls.
