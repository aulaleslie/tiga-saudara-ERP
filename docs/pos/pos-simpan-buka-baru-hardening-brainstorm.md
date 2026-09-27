# POS Simpan & Buka Baru - Hardening Brainstorm

Date: 2026-03-09
Scope: `http://localhost:8000/pos/sell` flow for saving current cart as a reusable document, listing it, and allowing another user to edit it.

## 1) What is already supported (verified now)

### Core POS cart + checkout
- [x] Cart APIs exist for show/add/update/remove/clear/customer/serial in `Modules/Pos/Routes/web.php`.
- [x] Cart snapshot already carries customer + lines + totals (`PosCartService::buildSnapshot`).
- [x] Remove item is supported (`DELETE /pos/sell/cart/lines/{lineId}`).
- [x] Checkout has idempotency + conflict handling (`FinalizePosCheckoutService`).
- [x] Checkout revalidates stock at finalization (`ResolvePosStockAllocationsService`).
- [x] Active POS session guard is enforced before sell/cart routes (`EnsureActivePosSessionMiddleware`).

### Price changes
- [x] Price override API exists (`POST /pos/sell/cart/lines/{lineId}/price-override`).
- [x] Price override requires `pos.overrides.price` permission and supervisor approval credentials.
- [ ] No visible UI on current sell page to trigger price override (backend-only capability today).

### Gaps against Simpan & Buka Baru goal
- [ ] `Simpan dan Buka Baru` button exists in UI but has no JS handler and no backend endpoint.
- [ ] No saved-cart document list page/endpoint.
- [ ] No API/service to persist current cart as a draft document.
- [ ] No cross-user collaborative edit flow for a shared saved document.
- [ ] Qty decrease is intentionally blocked today (`Jumlah qty tidak dapat dikurangi.`) in both UI behavior and backend service/tests.
- [ ] Current cart state is stored in HTTP session (`PosCartSessionStore`), not DB documents.
- [ ] Legacy draft schema/permissions were removed from active migrated state (`pos_drafts` not present).

## 2) Hardening checklist for Simpan & Buka Baru

### A. Product rules and boundaries
- [ ] Define precise behavior for `Simpan dan Buka Baru`:
  - save current cart/customer/notes/payment-intent as a document,
  - clear active cart,
  - optionally redirect/focus to a fresh cart.
- [ ] Define document lifecycle states (recommended: `OPEN`, `LOCKED`, `SUBMITTED`, `VOID`, `EXPIRED`).
- [ ] Decide if saved document edit rules differ from live-cart rules (especially qty decrease).

### B. Data model (persistent document)
- [ ] Create new transactional tables under current POS module (do not rely on removed legacy draft schema).
- [ ] Persist at minimum: `setting_id`, `document_number`, `status`, `customer_id`, `created_by`, `updated_by`, timestamps.
- [ ] Persist document items with `product_id`, `qty`, `unit_price`, `tax`, `serials`, `conversion_id`, line metadata.
- [ ] Add `version` (integer) for optimistic concurrency control.
- [ ] Add lock lease fields (`locked_by_user_id`, `locked_at`, `locked_until`) for collaborative edit safety.
- [ ] Add audit log table for before/after snapshots and actor attribution.
- [ ] Index for list usage: `(setting_id, status, updated_at)` and unique `(setting_id, document_number)`.

### C. Authorization and permission matrix
- [ ] Add/confirm granular permissions: view list, create/save, update, void, force-unlock, submit.
- [ ] Enforce `setting_id` isolation on every query/update.
- [ ] Decide whether cashier role can edit docs created by others by default, or only with dedicated permission.

### D. API contract
- [ ] Add endpoint to save current session cart into document (`save-and-new`).
- [ ] Add list endpoint with filters (`status`, `updated_at`, `cashier`, search by doc number/customer).
- [ ] Add endpoint to open/load document for editing.
- [ ] Add endpoint(s) for line edits: reduce qty, remove line, change price, change customer.
- [ ] Require `version` in mutation payloads; return `409` on stale updates.
- [ ] Add idempotency key for save/open actions to avoid duplicate document creation.

### E. Concurrency and collaboration
- [ ] Implement optimistic lock (`version`) as baseline.
- [ ] Implement soft lock lease to reduce edit collisions in UI.
- [ ] Define conflict UX: if another user updated the doc, show diff + refresh action.
- [ ] Decide lock expiry and override process (supervisor/permission-based).
- [ ] Prevent simultaneous submit/void/update race with DB transaction + row lock.

### F. Quantity, remove, and price-edit policy
- [ ] Relax qty-decrease rule for saved-document edits (currently blocked globally in `PosCartService`).
- [ ] Keep validation floor (`qty >= 0/1` depending on policy).
- [ ] Decide whether `qty=0` means auto-remove line.
- [ ] Keep or strengthen price override guard for edits by another user (supervisor approval, reason capture).
- [ ] Capture price-change audit reason (`manual correction`, `promo`, etc.).

### G. Stock + serial integrity
- [ ] Revalidate stock and serial availability when opening a saved document.
- [ ] Revalidate again at submit/finalize time.
- [ ] Define behavior for unavailable lines: mark invalid line, partial continue, or hard block.
- [ ] Ensure serial ownership/duplication checks remain strict across document edits.

### H. UI/UX hardening
- [ ] Wire `Simpan dan Buka Baru` button with loading, success, error, and idempotent retry handling.
- [ ] Add Saved Document list modal/page with clear ownership/lock status columns.
- [ ] Show who is editing and lock expiration countdown.
- [ ] Add explicit actions: `Ambil/Edit`, `Lihat`, `Void`, `Force Unlock` (permission-based).
- [ ] Show conflict banner when stale version detected.

### I. Observability and audit
- [ ] Log every document mutation with actor, endpoint, payload hash, and result.
- [ ] Add metrics: save success/failure, conflict rate, lock timeout, stale update rate.
- [ ] Track business events for support troubleshooting.

### J. Test coverage (must-have)
- [ ] Feature tests for: save-and-new, list visibility, open/edit by another user.
- [ ] Conflict tests: stale version update returns `409` and no partial write.
- [ ] Permission tests for each action.
- [ ] Qty decrease tests for saved documents (allowed) vs live cart (current rule, if retained).
- [ ] Price edit tests with/without supervisor approval.
- [ ] Stock/serial invalidation tests after save-before-submit timeline.
- [ ] Idempotency tests for repeated save click / network retry.

## 3) Key implementation decision to settle first

- [ ] Decide whether to:
  - build a **new persistent document service** (recommended), or
  - retrofit current session cart service.

Recommendation: keep current session cart behavior stable for active sell flow, and add a dedicated document aggregate/service for `Simpan dan Buka Baru` so collaboration and concurrency do not regress existing checkout behavior.
