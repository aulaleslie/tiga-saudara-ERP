# Design

## Context

See [proposal.md](proposal.md). `PosTransactionService::saveAndNew` already writes a draft, assigns its code, then clears the session cart. `loadToCart` requires an empty cart and changes a DRAFT to LOADED. The sell screen already opens `pos.transactions.receipt` after manual save. `PosReceiptService::getTransactionReceiptData` renders DRAFT and LOADED transactions through the shared `pos::receipt` view.

## Goals / Non-Goals

**Goals:** Keep one saved transaction identity through repeated edits and prints; reuse existing transaction snapshot mapping and receipt rendering; enforce the three permissions at the combined action boundary.

**Non-Goals:** Change checkout posting, payment collection, the existing receipt design, or the ordinary **Simpan dan Buka Baru** behavior. Browser automation is outside this proposal's verification plan; a human developer will test the browser flow.

## Decisions

### One guarded save-and-retain endpoint

Add a POST endpoint under the authenticated, enabled POS sell session routes. It requires `pos.transactions.save`, `pos.transactions.load`, and `pos.transactions.print-current`, plus the existing setting and active-session context. Implement a dedicated service operation that holds the cart mutation lock through persistence and rehydration. Reuse the existing save policy, snapshot mapper, transaction code generator, and load policy. For a new cart, create one DRAFT then set it as the current LOADED transaction. For an already loaded draft, update that same transaction and restore its active cart. Return its ID, code, and fresh cart snapshot. Do not have the browser call `save-and-new` followed by `/load`: that creates a gap with an empty cart and a loadable draft.

The implementation must respect the existing cart-store and database transaction ordering. If a late cart-store failure occurs after draft persistence, keep the draft recoverable through the normal transaction list and return a failure instead of printing a stale receipt. The existing cart lock guards concurrent cart mutations; server policy checks guard ownership, scope, and allowed lifecycle state.

### Reuse the existing draft receipt URL and view

After the combined action returns successfully, the browser refreshes the cart from the returned snapshot and navigates a new window to `pos.transactions.receipt` for the returned ID. The receipt view remains identical to existing draft printing. Open a blank window synchronously on the click to avoid popup blocking during the asynchronous save; close it or show a Bahasa Indonesia error if save fails. Trigger `window.print()` from the loaded receipt window, while keeping the existing manual print control usable.

### Permissions and button placement

Register `pos.transactions.print-current` in the centralized permission list and POS capability surfaces. Do not infer authorization from receipt reprint permission. Show **Simpan dan Cetak** only when the user has all three required permissions. Place it beside **Simpan dan Buka Baru** in a secondary action row, with **Pilih Pembayaran** as the primary full-width row below; stack controls on narrow screens. Keep all newly added visible text in Bahasa Indonesia.

## Risks / Trade-offs

- **Cart-store failure after database persistence** → Return an error, avoid printing, and leave the persisted draft available for recovery from the transaction list. Do not claim cross-store atomicity.
- **Browser popup or print dialog is blocked or cancelled** → The draft remains loaded; show a Bahasa Indonesia message and allow retrying the print action without a new transaction code.
- **Repeated clicks or stale tabs** → Disable the button while pending and use the existing cart mutation lock and lifecycle checks on the server. A stale request must fail rather than overwrite another cart revision.
- **Receipt requested after cart is edited again** → The receipt route reads the saved transaction snapshot, so it shows the state from the completed print save, not later unsaved edits.

## Migration Plan

Register and assign the new permission through existing permission seeding/synchronization. No database schema migration is needed. Rollback removes the new UI and endpoint; any drafts created by the action remain valid ordinary POS drafts.
