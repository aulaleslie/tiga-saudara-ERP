# Design

## Context

See proposal.md for motivation. `PosTransactionService::unload()` already changes LOADED to DRAFT when a loaded cart is cleared. The transaction list only offers Muat for DRAFT rows. Cart contents live in the browser's server-side session, separately from the saved transaction snapshot. Existing transaction routes enforce the active business, POS availability, and permissions.

## Goals / Non-Goals

**Goals:** Expose manual recovery through the existing list and preserve normal draft loading, including snapshot integrity checks.

**Non-Goals:** Detect abandonment, expire loads, invalidate other browser carts, recover unsaved edits, introduce permissions/approvals/audit infrastructure, or change checkout behavior. Verification is focused; no full-suite run is planned.

## Decisions

### Use existing load authority

Require `pos.transactions.view` and `pos.transactions.load`, alongside existing authentication, business scope, POS access, and transaction feature gates. Choose existing load authority because recovery enables the same continuation workflow. A separate recovery permission would add administration beyond this small change. Do not require an active POS session for recovery; the later Muat action keeps its existing session requirements.

### Add an explicit status-only recovery operation

Add POST `/pos/transactions/{transaction}/reset-to-draft` and controller/service handling. Scope the lookup to the active setting and recheck LOADED under a row lock inside a database transaction before updating status to DRAFT. Reject any other status with the existing validation response convention, including repeated requests after success. Return the transaction ID, code, status, and an Indonesian success message.

Use a dedicated operation rather than exposing the current silent-no-op `unload()` directly: recovery needs explicit rejection and a fresh status check, while existing cart-clear behavior can remain unchanged. Preserve saved lines, totals, customer, note, ownership, snapshot hash, and checkout linkage; normal model timestamp behavior can apply. No stock, payment, or sale operation is invoked.

### Integrate into the transaction list

Render **Kembalikan ke Draf** only for LOADED rows when the user has load authority. Use the existing JSON request and status-message patterns, disable the button during submission, then refresh rows with the current filters. A LOADED-only filter may remove the recovered row; switching to DRAFT reveals it with Muat. On failure, display the server message without reporting success. Do not automatically load or redirect.

### Focus verification on observable recovery behavior

Add focused feature coverage using the existing POS transaction test fixtures: successful reset with saved data preserved, reset followed by normal load, unauthorized requests, foreign-business requests, disabled feature, and rejection of DRAFT/COMPLETED/CANCELLED. Use a small manual list check for button visibility, in-flight disabling, error messages, and filter-preserving refresh. Run only relevant test classes/filters, including existing unload and load coverage when shared behavior is touched. No full-suite command or broad migration sweep is required.

## Risks / Trade-offs

- An abandoned browser can still retain its cart and later submit edits → Recovery assumes that cart is abandoned, as agreed; cross-session invalidation is outside scope.
- Unsaved edits in the old cart are absent from the saved draft → Reload uses the last persisted snapshot, matching the database workaround.
- Another request may change status after the list is displayed → Check the current status under a row lock and reject an ineligible reset.
- Existing snapshot or lifecycle validation can still prevent loading → Preserve those checks; recovery removes only the LOADED status barrier.

## Migration Plan

Deploy the route, controller/service operation, and Blade changes together. No migrations or backfill are needed. Roll back by reverting those changes; recovered transactions remain ordinary DRAFT records.
