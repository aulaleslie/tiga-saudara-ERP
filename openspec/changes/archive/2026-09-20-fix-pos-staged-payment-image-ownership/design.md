# Design

## Context

See `proposal.md` for motivation. The staged checkout currently keeps one mutable image token for the active form. After a stage succeeds, the server stores that token in the cart-scoped session payment chain, but the client retains the same token and its generic form reset invokes the individual deletion endpoint. The endpoint validates cashier, setting, POS session, cart, activity, and expiry, but does not distinguish an uncommitted upload from a token already referenced by the payment chain.

The existing finalization path correctly maps committed stage tokens into normalized payments, validates their scope, and attaches them to the originating Sale Payments. Complete-chain reset already removes all temporary images for the scoped cart. These behaviors should remain the lifecycle boundaries.

## Goals / Non-Goals

**Goals:**

- Establish an explicit ownership transition from active-form image to committed payment-chain image.
- Make individual deletion safe at both the browser and server boundaries.
- Preserve retry, reload recovery, payment-stage isolation, and split-posting attachment behavior.
- Cover the reported partial Transfer followed by Utang/Kas Bon path with focused verification.

**Non-Goals:**

- Change the 24-hour temporary-image expiry policy.
- Change debt authorization, payment-term, amount, or posting rules.
- Add database columns, migrate historical data, or redesign the payment chain.
- Introduce a JavaScript test framework or require the full application test suite.

## Decisions

### Separate local clearing from destructive deletion

Use distinct client operations for clearing the active image controls and deleting a pending upload. A successful stage response transfers ownership to the server payment chain, so the client clears its local pointer and presentation without issuing DELETE. Explicit pre-stage removal, switching to Cash while a pending upload exists, or abandoning a pending form may invoke deletion.

This is preferred over merely removing deletion from payment-method visibility because that would retain abandoned uncommitted uploads until expiry. It is also preferred over keeping a committed token in the active form because a later stage could accidentally inherit it.

### Capture and detach a pending token before asynchronous deletion

The client will copy the token being removed into an operation-local value and synchronously detach it from active form state before awaiting the request. Completion of the old request will not reset shared image state. This prevents a delayed deletion response from clearing a replacement upload and prevents a rapidly selected Cash stage from submitting the old token.

### Enforce chain ownership at the deletion endpoint

Before individually deleting a scoped token, the endpoint will inspect the cart-scoped session payment chain and compare the token with every committed stage image reference. A referenced token will receive a stable conflict response and remain intact. Unreferenced scoped tokens retain existing deletion behavior. Complete-chain reset remains the intentional destructive boundary and continues deleting all cart-scoped temporary images after removing the chain.

Client ownership handling alone was rejected because stale assets, duplicate events, or direct requests could still delete a committed token.

### Keep finalization and media attachment paths unchanged

Finalization will continue resolving active tokens from the session chain and existing posting adapters will continue consuming and attaching images. The correction is limited to preventing premature deletion; it does not add fallback behavior that silently drops missing evidence.

### Use focused verification only

Focused Laravel feature tests will exercise server ownership protection and complete staged flows for partial Transfer followed by Utang/Kas Bon and Transfer followed by Cash. Existing nearby tests will cover pending removal and full-chain reset. A lightweight source-level or existing-browser-pattern assertion may cover the client transition if practical without introducing a new JavaScript test dependency. No full-suite run is planned.

## Risks / Trade-offs

- [Session-chain inspection and individual deletion can arrive concurrently] → Make the browser transfer ownership immediately on a successful stage response and use the server conflict guard for established chain ownership; do not weaken finalization validation.
- [A stale browser may receive a conflict while attempting cleanup] → Treat the committed-token conflict as preservation, clear only the stale active-form presentation, and leave the chain-owned image intact.
- [An abandoned pending upload may survive a network failure] → Retain the existing bounded expiry cleanup rather than risking deletion of committed evidence.
- [Cached JavaScript may retain the faulty transition after deployment] → Ensure the deployed asset URL/version or normal cache-busting mechanism changes with the updated public asset.

## Migration Plan

Deploy the client and server changes together with no schema migration. Rollback restores the prior client and endpoint behavior; existing temporary records remain compatible and continue to expire through the current cleanup process.
