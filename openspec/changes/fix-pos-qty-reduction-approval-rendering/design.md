## Context

The POS cart already supports supervised actions (`clear cart`, `line remove`, `qty reduce`) for non-authorized users. Backend approval requests are created correctly and persisted in `pending_approvals`, but quantity-reduction follow-up controls are not consistently rendered in the cart UI.

Investigation showed two primary integration issues in the frontend flow:
- Mixed approval object shapes between client cache and server snapshot (`requestId` vs `request_id`, `requestedQty` vs payload-based qty).
- A cart re-render path using `/pos/sell/cart` response directly instead of `response.cart_snapshot`, causing render code to miss `lines` and `pending_approvals`.

This change is scoped to UI state resolution and response-contract handling in POS sell cart rendering.

## Goals / Non-Goals

**Goals:**
- Ensure quantity-reduction approval controls render deterministically after request submission and after full cart refresh.
- Make qty reduction state transitions consistent with existing supervised action UI patterns used by delete/clear actions.
- Keep backend approval request lifecycle and API contracts unchanged.
- Add regression coverage for the render-state bug.

**Non-Goals:**
- Redesigning the ApprovalManager lifecycle or modal UX.
- Introducing new backend endpoints, tables, or approval states.
- Refactoring unrelated cart rendering features outside supervised qty reduction state handling.

## Decisions

### Decision 1: Use a canonical frontend approval-state shape for qty reduction rendering
Create a single normalization path for qty-reduction approval data before rendering controls. Both client-cached and server-sourced records will be mapped into one canonical shape consumed by row rendering.

Rationale:
- Removes key-format ambiguity (`requestId` vs `request_id`).
- Prevents branch divergence between serial and non-serial row templates.
- Makes conditional checks deterministic across pending/approved/rejected/cancelled states.

Alternatives considered:
- Update each template branch with ad-hoc key fallbacks.
: Rejected because duplicated conditionals are error-prone and hard to keep aligned.

### Decision 2: Treat server `pending_approvals` snapshot as source of truth after refresh
When a fresh cart snapshot exists, row rendering resolves qty approval state from `line.pending_approvals`; client cache remains an immediate fallback only for transient in-flight UI states.

Rationale:
- Server snapshot survives reloads and reflects supervisor decisions.
- Avoids stale client entries masking newer server states.

Alternatives considered:
- Keep client cache precedence over server data.
: Rejected because it can preserve outdated state after approval/rejection.

### Decision 3: Enforce cart-show response contract before render
All `/pos/sell/cart` refresh paths must pass `response.cart_snapshot` to `renderCart`.

Rationale:
- Matches existing renderer expectations (`snapshot.lines`, `snapshot.pending_approvals`).
- Prevents empty/incorrect rows when wrapper objects are rendered directly.

Alternatives considered:
- Modify renderer to accept both wrapped and unwrapped payloads.
: Rejected to keep one stable contract and reduce hidden branching.

## Risks / Trade-offs

- [Risk] Serial and non-serial row render paths can drift again in future edits.
  → Mitigation: use shared normalized approval-state input and mirror conditions across both paths.

- [Risk] Additional fetch-after-submit can increase perceived latency on weak networks.
  → Mitigation: keep optimistic local state as temporary fallback while using server snapshot as final authority.

- [Risk] Existing behavior for approved token handling could regress if normalization drops token fields.
  → Mitigation: preserve token extraction from both `token` and `approval_token` during normalization.

## Migration Plan

1. Implement frontend-only adjustments in POS sell cart rendering and qty approval state mapping.
2. Validate scenarios manually: request pending display, refresh persistence, approve/reject transitions, and execute/cancel paths.
3. Run relevant POS approval feature tests.
4. Rollback plan: revert sell-view/frontend changes for this change set (no schema/data migration involved).

## Open Questions

- Should transient client-side cache be retained long term, or removed in favor of server-only render state once this bug is resolved?
- Do we want a dedicated frontend helper module for approval-state normalization to prevent future inline duplication in `sell.blade.php`?
