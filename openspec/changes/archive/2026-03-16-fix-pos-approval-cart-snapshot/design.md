## Context

The POS approval workflow has two endpoints with different response contracts:
- **Cart mutation endpoints** (`POST /pos/sell/cart-lines`, `DELETE /pos/sell/cart-lines/{id}`, etc.) return `{cart_snapshot, ...}`
- **Approval request endpoint** (`POST /pos/sell/approval-requests`) returns only `{request_id, status}`

The frontend `requestApproval()` handler manually updates the button but never calls `renderCart()`, so `buildLineRow()` is never invoked. This means the line's full approval state metadata is never evaluated and the "Periksa Persetujuan" button doesn't render.

The fix is to align the approval endpoint's response contract with other cart mutation endpoints by including `cart_snapshot`.

## Goals / Non-Goals

**Goals:**
- Make the approval request endpoint return a complete `cart_snapshot` contract
- Ensure frontend `requestApproval()` can call `renderCart(snapshot)` immediately after success
- Enable `buildLineRow()` to be called for all lines, evaluating and rendering approval state correctly
- Maintain backward compatibility (only adding a field, not changing existing ones)

**Non-Goals:**
- Refactoring the entire approval workflow architecture
- Changing how approval state is stored or queried
- Modifying the frontend's approval state evaluation logic

## Decisions

**Decision 1: Where to inject `cart_snapshot`**
- **Choice**: In `PosCartApprovalController::store()` after successful request creation
- **Rationale**: The controller already has access to `settingId` and `sessionId`, and can inject `PosCartService` to fetch the snapshot. This keeps the change localized and consistent with how other endpoints work.
- **Alternative**: Call from the service layer (PosApprovalRequestService). Rejected because approval requests are domain-agnostic and shouldn't know about cart snapshots; this responsibility belongs in the controller layer.

**Decision 2: Dependency injection strategy**
- **Choice**: Constructor inject `PosCartService` into `PosCartApprovalController`
- **Rationale**: Already a pattern in Laravel/this codebase; provides clean dependency management
- **Alternative**: Service locator (e.g., `app(PosCartService::class)`). Rejected in favor of explicit injection for testability.

**Decision 3: Performance consideration**
- **Choice**: Accept the lightweight `getSnapshot()` call (reads session store, calculates totals)
- **Rationale**: `getSnapshot()` is already called on every cart mutation endpoint and is not expensive. It's the standard contract for cart endpoints.
- **Risk mitigated**: If performance becomes an issue, this is a future optimization point, but current usage patterns don't show concern.

## Risks / Trade-offs

**[Risk] Response payload size increases**
- Mitigation: The cart_snapshot is only a few KB of JSON (lines array, totals, customer, metadata). Acceptable for a non-list endpoint. If this becomes problematic, we can consider pagination or compression later.

**[Risk] Snapshot might be stale if another request modifies cart during processing**
- Mitigation: In POS, the session/cart is tied to a single terminal user. Concurrent cart modifications from the same session are rare. If needed, we can add optimistic locking later.

**[Risk] Approval request service doesn't expose snapshot**
- Mitigation: Snapshot is fetched in the controller after request creation, not in the service. Service remains domain-focused; controller handles the presentation contract.

## Migration Plan

1. Add `PosCartService` dependency to `PosCartApprovalController`
2. Update `store()` method to fetch and include `cart_snapshot` in the response
3. Update feature test to assert `cart_snapshot` is present
4. Existing frontend code already expects this contract (from spec), so no frontend changes needed
5. Deploy and monitor for any response size issues

## Open Questions

None. The design is straightforward and follows existing patterns in the codebase.
