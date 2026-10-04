# Design

## Context

The POS sell page renders result cards and currently hides `pos-search-results-modal` before calling `addProductToCart(product, 'manual')`. Search query and result DOM persist until a successful transaction-boundary reset. `addProductToCart` can open sales-unit or bundle dialogs before sending a cart request; the cart service already merges matching lines. See `proposal.md` for motivation and `specs/pos-product-search-lifecycle/spec.md` for behavior.

## Goals / Non-Goals

**Goals:**
- Make the modal transition depend on whether the clicked result needs a follow-up selection.
- Reuse the existing add-to-cart and cart-line merge path.
- Keep repeated clicks deliberate and prevent overlapping requests from losing quantity updates or replacing an active selection operation.

**Non-Goals:**
- Returning automatically to search after sales-unit or bundle selection.
- Changing search retrieval, cart identity, pricing, stock checks, or transaction-boundary resets.

## Decisions

1. **Decide the modal transition at the result-card click.** Use the same unit and bundle eligibility conditions already used by `addProductToCart`; close search only when the product will enter one of those choice flows. Keep the shared add-to-cart path for all result types. This keeps modal behavior aligned with the actual selection path. A separate search-only cart implementation would duplicate validation and merge logic.
2. **Serialize simple-product selections.** Queue result-card clicks in click order and process one add-to-cart request at a time. Keep the modal and results visible throughout. This avoids concurrent cart snapshots overwriting one another and ensures each click represents one unit. Preserve visible error feedback through the existing cart/search status path.
3. **Preserve search state and transaction resets.** Do not clear the modal query or result cards when adding a product. Continue relying on `resetProductSearchModalState()` after successful transaction boundaries. Do not refresh result cards after every add; the server remains authoritative for stock validation.
4. **Keep focus within the open search modal.** After a simple-product add completes, avoid the current unconditional scanner-input focus because it would move keyboard focus behind the open modal. For unit and bundle paths, retain the current focus behavior when search closes.

## Risks / Trade-offs

- **Displayed stock can become stale after repeated adds** → The cart endpoint validates available quantity and the user sees its error; no automatic result refresh is added in this change.
- **Rapid clicks may overlap selection operations** → Process queued card selections one at a time so the cart reflects each click exactly once.
- **Modal focus can escape to the scanner after add** → Condition the focus step on whether search remains open.

## Migration Plan

No data migration is needed. Deployment changes the POS sell-page interaction only. Rollback restores the previous result-card modal-close behavior.
