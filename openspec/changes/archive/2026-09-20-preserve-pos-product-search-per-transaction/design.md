# Design

## Context

The POS sell page owns product-search state in browser memory. Its `Cari Produk` click handler currently clears the modal input and replaces the result container every time the modal opens. Search responses already use a monotonically increasing request identifier to ignore superseded responses. Regular checkout, staged checkout, and save-as-draft-and-new establish a new cart context without reloading the page, while the current draft-load flow redirects to the sell page after loading the cart.

## Goals / Non-Goals

**Goals:**

- Give product-search state an explicit lifetime matching the active POS transaction context.
- Centralize reset behavior so every successful in-page transaction boundary applies the same cleanup.
- Reuse request-generation tracking to prevent a previous transaction's late response from restoring stale results.
- Keep existing search, result rendering, stock visibility, selection, and server-side cart validation behavior unchanged.

**Non-Goals:**

- Persist search state across browser reloads, navigation away from the POS sell page, logout, or a new POS session.
- Re-query or live-update preserved result cards after cart mutations.
- Change product-search endpoints, cart APIs, checkout APIs, draft persistence, or database schema.
- Require the repository's full test suite for verification.

## Decisions

### Keep modal state in the existing sell-page client runtime

The modal input and rendered result DOM already represent the necessary state. Opening the modal will stop clearing those elements, allowing close, selection, and reopen actions to preserve them naturally.

Persisting the state in session storage or on the server was considered, but would incorrectly extend its lifetime across reloads or navigation and would add synchronization work not required by the transaction-local behavior.

### Add one transaction-boundary search reset operation

A single client-side reset operation will clear the modal query, restore the initial empty-search presentation, and advance the search request generation. Successful regular checkout, successful staged checkout, successful save-as-draft-and-new, and successful cart clearing (`Kosongkan Keranjang`) will call this operation only after their server-confirmed success.

Cart clearing goes through `ApprovalManager.wrapAction`, whose callback only executes once the `CART_CLEAR` action is actually approved and the clear endpoint returns a response; a denied, cancelled, or still-pending-approval clear never reaches that callback, so the reset naturally applies only to the successful case without extra state tracking.

Duplicating cleanup in each success handler was considered, but a shared operation reduces drift between checkout variants and ensures stale-request invalidation is not omitted from one path.

### Let successful draft loading reset through sell-page initialization

The existing draft-load flow performs the load request from the transaction page and navigates to the POS sell page only after success. A fresh sell-page runtime therefore provides the required empty search state. A failed load remains on the originating page and does not establish a new sell-page transaction context.

Adding persisted cross-page search state was rejected because it would create the very state leakage this change is intended to prevent.

### Preserve result snapshots until an explicit boundary or new search

Result cards will not be automatically refreshed after a selected product changes the cart. Existing server-side add-to-cart validation remains authoritative if displayed availability becomes stale. Users can explicitly run another search when they need refreshed catalog or stock results.

Automatically re-querying after selection was rejected because it reproduces the disruptive refresh behavior and changes the requested interaction model.

## Risks / Trade-offs

- [Preserved stock text can become stale after cart changes] → Continue relying on authoritative server-side cart validation and allow the user to explicitly rerun the search.
- [A late asynchronous response could repopulate state after checkout or save-and-new] → Advance the existing request generation as part of the reset and ignore responses from older generations.
- [One success boundary could omit the reset] → Route regular checkout, staged checkout, save-as-draft-and-new, and cart-clear success callbacks through the same reset operation and cover each with focused verification.
- [DOM-only state is lost on browser reload] → Accept this as intentional because persistence is scoped to the current page runtime and transaction workflow.
