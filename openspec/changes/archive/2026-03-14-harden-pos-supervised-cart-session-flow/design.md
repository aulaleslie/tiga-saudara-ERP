## Context

POS operational flow now spans three roles with different authority levels: Floor Staff (assist shopping and save draft), Cashier Staff (continue draft and charge payment), and Store Manager (full override authority). The current implementation already contains asynchronous approval primitives and supervisor queue endpoints, but role-policy UX/state handling is inconsistent, terminal/session ownership rules are insufficient to prevent user-terminal clashes, and transaction listing semantics can omit completed records depending on how checkout was initiated.

The change must preserve existing granular permissions while tightening behavior around restricted cart actions, approval lifecycle determinism, session ownership, and transaction visibility.

## Goals / Non-Goals

**Goals:**
- Enforce clear role boundaries for cart mutation, payment, and price override behavior.
- Normalize approval lifecycle for restricted actions (`clear`, `remove`, `reduce`, `price change`) with deterministic request states.
- Prevent POS session collisions by enforcing user and terminal ownership invariants.
- Keep floor-to-cashier handoff reliable through draft transaction numbers.
- Make transaction list defaults unambiguous: no filter means all statuses including completed.

**Non-Goals:**
- Redesigning the POS UI layout beyond required action-state controls.
- Introducing new role entities (existing role + granular permission model remains authoritative).
- Replacing current approval model with external workflow engines.
- Changing historical transaction semantics beyond list visibility and mutability rules.

## Decisions

### Decision 1: Role policy is enforced server-first, reflected client-side
All action gates are treated as backend authorization decisions, with frontend button states mirroring server outcomes. Unauthorized attempts for restricted actions MUST generate approval requests rather than silently failing.

Alternatives considered:
- Client-only role gating: rejected because it can drift from backend permission checks and is bypassable.
- Endpoint-per-role split: rejected to avoid duplicating action logic and increasing API surface.

### Decision 2: Restricted action approval uses explicit state machine
Restricted actions follow a single lifecycle: `idle -> pending -> approved|rejected -> confirmed|cancelled`. Users without direct permission submit request (optional reason), then manually trigger `Periksa Persetujuan` checks. Approved actions require explicit `Lanjutkan`; `Batalkan` leaves cart unchanged.

Alternatives considered:
- Auto-polling immediately after request: rejected because desired UX requires user-driven check and retry.
- One-click auto-execute on approval: rejected because operators need final confirmation before mutation.

### Decision 3: Price override follows same supervisory workflow for non-authorized users
Non-authorized users requesting sales-price changes use the same asynchronous approval pattern as other restricted cart mutations. Authorized manager-level permission can execute price override directly with audit metadata.

Alternatives considered:
- Keep supervisor-credential prompt inside transaction form: rejected for inconsistent UX and weaker queue visibility.

### Decision 4: Session anti-clash invariants are explicit
Session opening obeys both invariants:
- At most one active POS session per `(setting, user)`.
- At most one active POS session per `(setting, terminal)` for terminal-bound sessions.

Role terminal rules:
- Floor Staff and Store Manager can open POS without terminal selection.
- Cashier Staff must select terminal.

When conflicts occur, the system returns explicit conflict outcomes instead of silently switching ownership.

Alternatives considered:
- Auto-closing conflicting sessions: rejected to avoid hidden ownership changes.
- Terminal required for all roles: rejected because it conflicts with desired floor/manager flow.

### Decision 5: Draft handoff and list visibility are normalized
`Simpan dan Buka Baru` remains the draft handoff mechanism. Draft records stay editable; completed records are immutable. Transaction list with no filter defaults to all statuses including completed. Checkout paths must ensure completed sales are represented in transaction history.

Alternatives considered:
- Showing draft-only by default: rejected because it hides completed records and weakens audit visibility.

## Risks / Trade-offs

- [Approval backlog can slow cashier operations] -> Mitigation: queue SLA expectations, clear pending/rejected statuses, and retry-friendly check action.
- [More state transitions increase UI complexity] -> Mitigation: enforce a shared action-state contract across clear/remove/reduce/price-change flows.
- [Stricter session invariants can block users in edge cases] -> Mitigation: explicit conflict messaging and manager-supported operational resolution.
- [Ensuring completed visibility may reveal historical inconsistencies] -> Mitigation: include compatibility handling for legacy rows during rollout and surface data-quality exceptions.

## Migration Plan

1. Introduce and validate role-policy + approval state-contract changes behind existing permissions.
2. Enforce terminal/user active-session invariants and role-based terminal selection rules for session opening.
3. Align transaction list query defaults and ensure checkout persistence paths consistently represent completed transactions.
4. Deploy with targeted POS regression suite coverage for role matrix, approval lifecycle, conflict handling, and list defaults.
5. Rollback strategy: revert to prior route/controller/service behavior and disable new invariant enforcement if operational blockers occur.

## Open Questions

- Should managers be allowed to force-take over conflicting terminal sessions, or only resolve via explicit close flow?
- For pending approval requests tied to cart lines later changed by other actions, should requests auto-expire or remain actionable with snapshot validation?
- Should approval rejection require mandatory reason for audit quality, or remain optional?
