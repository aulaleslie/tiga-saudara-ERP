## Context

The POS backend already fully supports price overrides with approval governance:
- `PosActionApprovalRequest::ACTION_PRICE_OVERRIDE` action type is defined
- `PosCartActionAuthorizationService` maps `PRICE_OVERRIDE` → `pos.overrides.price` permission
- `PosCartService::overrideLinePrice()` handles both direct (privileged) and token-based (approved) overrides
- `PosSellController::cartOverridePrice()` endpoint is registered at `POST /pos/sell/cart/lines/{lineId}/price-override`
- `PosRolePolicyService::capabilityFlags()` exposes `direct_permissions.price_override` to the frontend
- The supervisor approval queue already renders and processes `PRICE_OVERRIDE` requests

The sell UI currently renders price as static text: `formatPrice(line.unit_price || 0)`. There is no way to trigger a price change from the interface.

The frontend already has a mature `ApprovalManager.wrapAction()` pattern used by `LINE_REMOVE`, `CART_CLEAR`, and `QTY_REDUCE`. This pattern transparently handles both privileged (direct execution) and non-privileged (approval flow) users through the same code path.

## Goals / Non-Goals

**Goals:**
- Enable all POS users to edit prices through a modal dialog
- Reuse the existing `ApprovalManager.wrapAction()` pattern (same as line remove, cart clear, qty reduce) for consistent UX and minimal new code
- Render PRICE_OVERRIDE approval state on the price cell (Pending → Periksa, Approved → Lanjutkan) matching established patterns
- Allow zero-value prices (user intent to set price = 0 is a valid use case)
- Enrich the snapshot to expose `requested_unit_price` from PRICE_OVERRIDE approval payloads

**Non-Goals:**
- Implementing minimum/maximum price bounds or percentage deviation limits
- Adding price override to the supervisor approval queue UI (already works)
- Creating new permissions or role definitions
- Modifying the checkout or receipt flow for overridden prices (already handled)

## Decisions

### Decision 1: Always use a modal for price editing (not inline input)

**Choice:** All users (privileged and non-privileged) edit price through a modal dialog showing current price and a target price input.

**Rationale:** Consistent with the qty reduction pattern. A modal provides space for showing old vs. new price side by side and prevents accidental edits. The `ApprovalManager.wrapAction()` is called on modal submit, meaning the same modal works for both user types — the backend determines whether approval is needed.

**Alternatives considered:**
- Inline editable input for privileged users: Rejected because it diverges from the modal-first pattern used by qty reduction and would require separate UI paths for privileged vs. non-privileged users.

### Decision 2: Price edit trigger is a button on the price cell, not on the action column

**Choice:** Add a small edit button (pencil icon) adjacent to the price display within the price `<td>` cell. For lines with a pending/approved PRICE_OVERRIDE, this button transforms into a status button (Periksa / Lanjutkan), matching the LINE_REMOVE pattern on the delete button.

**Rationale:** Keeps the edit affordance close to the value being edited. The action column is already occupied by the delete button. The approval-state rendering mirrors the delete button pattern exactly (same data attributes: `data-approval-pending`, `data-approval-token`).

### Decision 3: Reuse `ApprovalManager.wrapAction()` without modification

**Choice:** The price override flow calls `ApprovalManager.wrapAction()` with action type `PRICE_OVERRIDE`, target type `pos_cart_line`, and payload `{ unit_price: newPrice }`. The action function POSTs to the existing `/cart/lines/{id}/price-override` endpoint.

**Rationale:** This is identical to how `LINE_REMOVE` and `CART_CLEAR` work. The `ApprovalManager` already:
1. Attempts the action directly
2. Catches `APPROVAL_REQUIRED` and creates an approval request
3. Manages pending/approved/cancelled state on the button element
4. Re-renders cart after successful execution

No changes to `ApprovalManager` are needed.

### Decision 4: Allow zero prices by relaxing backend validation

**Choice:** Change validation from `$unitPrice <= 0` to `$unitPrice < 0` in `PosCartService::overrideLinePrice()` and from `gt:0` to `gte:0` in `StorePosCartPriceOverrideRequest`.

**Rationale:** Users intentionally set prices to 0 (e.g., promotional giveaways, warranty replacements). The approval flow already governs who can make such changes.

### Decision 5: Enrich snapshot with `requested_unit_price`

**Choice:** In `PosCartService::buildSnapshot()`, extract `unit_price` from the approval request payload alongside the existing `qty` extraction.

**Rationale:** The frontend needs to display the approved target price on the Lanjutkan button (e.g., "✓ Rp 50.000") so the user knows what price will be applied before confirming.

## Risks / Trade-offs

- **[Zero-price abuse]** → Mitigated by the approval flow for non-privileged users. Privileged users already have `pos.overrides.price` permission which implies trust.
- **[Approval state collision with QTY_REDUCE]** → Both can have pending approvals on the same line. The `pending_approvals` array already supports multiple entries per line, and each has a distinct `action_type`. The price button and qty button each filter for their own action type, so no collision occurs.
- **[Large sell.blade.php]** → Adding more JS to an already large file. Mitigated by keeping changes minimal — the modal partial is extracted to a separate file, and the JS handler follows the exact existing pattern requiring minimal new code.
