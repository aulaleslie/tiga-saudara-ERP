## Context

The POS sell cart currently renders quantity controls through multiple branch-specific templates in `sell.blade.php` (serial vs non-serial, privileged vs non-privileged). For non-privileged flows, reduce/approval controls are composed differently per branch, which causes visual inconsistency and duplicated state logic.

Quantity-reduction approvals are available through existing supervised-action APIs and snapshot metadata, but frontend transitions after `Periksa` can still depend on stale in-memory render state. A full page refresh restores expected controls because server `cart_snapshot.lines[*].pending_approvals` is authoritative.

This change keeps backend approval lifecycle intact and focuses on deterministic frontend composition and refresh behavior for qty controls.

## Goals / Non-Goals

**Goals:**
- Render one consistent non-privileged qty control strip across serial and non-serial rows: `[Reduce/Periksa slot][qty][+]`.
- Keep serial action controls available as a secondary line without changing serial modal workflow.
- Remove duplicated qty approval button composition paths so row types share the same state-to-UI mapping.
- Ensure post-`Periksa` transitions converge to latest server-backed approval state without requiring page reload.
- Preserve existing role/permission behavior and approval request API contract.

**Non-Goals:**
- Changing approval request entities, endpoints, or token semantics.
- Redesigning modal UX for quantity reduction or serial management.
- Refactoring unrelated cart features (pricing, checkout, delete workflow semantics).

## Decisions

### Decision 1: Introduce a shared qty-control composition path for non-privileged rows
Use one renderer path for the left approval slot and qty stepper controls, reused by both serial and non-serial templates.

Rationale:
- Eliminates branch drift where one row type diverges in spacing/state.
- Makes structural layout guarantees testable and stable.

Alternatives considered:
- Keep separate serial/non-serial markup and synchronize manually.
: Rejected due repeated regressions from duplicate conditional blocks.

### Decision 2: Reserve a fixed left slot for reduce/approval controls
Render exactly one control in the first slot (`reduce`, `Periksa`, or `approved qty`) and keep slot dimensions stable across states.

Rationale:
- Prevents control jumping when label length changes.
- Maintains predictable pointer targets for cashier workflows.

Alternatives considered:
- Let controls auto-size with content.
: Rejected because width changes cause noticeable layout jitter.

### Decision 3: Treat server snapshot as authoritative after approval checks
After `Periksa` interaction, refresh and re-render from the latest `cart_snapshot` so row state reflects canonical `pending_approvals` and tokens.

Rationale:
- Avoids stale in-memory state artifacts.
- Matches existing behavior where hard refresh fixes mismatches.

Alternatives considered:
- Mutate only local button attributes and re-render current snapshot.
: Rejected because current snapshot can be stale relative to supervisor decisions.

### Decision 4: Keep a single approval-state mapper for mixed metadata inputs
Continue using a canonical mapper for `request_id/requestId`, `token/approval_token`, and approved qty fields, but route final rendering through one button-state function.

Rationale:
- Supports transient client fallback when needed.
- Prevents key-shape mismatches from leaking into view branching.

Alternatives considered:
- Inline key fallbacks inside each template branch.
: Rejected due low maintainability and higher regression risk.

## Risks / Trade-offs

- [Risk] Serial-row layout may become denser after unifying top controls.
  → Mitigation: keep serial action on a second line and preserve chip wrapping behavior.

- [Risk] Additional post-check snapshot fetch can add latency on unstable networks.
  → Mitigation: show status feedback immediately; use fallback render path only if refresh fails.

- [Risk] Shared renderer refactor may accidentally alter privileged row behavior.
  → Mitigation: constrain unification to non-privileged path and cover privileged flow with targeted regression tests.

## Migration Plan

1. Refactor non-privileged qty control rendering into shared composition helpers in `sell.blade.php`.
2. Apply consistent slot-first layout to both serial and non-serial row templates.
3. Align `Periksa` transition handling to refresh from latest `cart_snapshot` before final render.
4. Extend/adjust feature tests for layout/state expectations in supervised qty reduction paths.
5. Rollback strategy: revert frontend view/test changes only; no schema or data migration involved.

## Open Questions

- Should the same shared qty-control component be extended to privileged rows now, or deferred to a later cleanup change?
