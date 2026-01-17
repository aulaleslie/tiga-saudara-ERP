# Purchase Return Settlement — Execution Plan

## Recommended Order
1. Ticket 1: Add per-line approval fields to settlement items.
2. Ticket 2: Update settlement entry UI for drafts and per-line submit.
3. Ticket 3: Implement per-line approval and settlement effects.
4. Ticket 4: Roll-up status derivation for header and purchase return.
5. Ticket 5: Detail, list, and print UI updates for per-line status.
6. Ticket 6: Permissions and gating adjustments.
7. Ticket 7: Tests and regression coverage.

## Parallelizable Tasks
- Ticket 4 and Ticket 5 can start after Ticket 3 is stable (status rules and per-line approvals defined).
- Ticket 6 can be prepared in parallel with Tickets 4–5 (permission mapping and UI gating plan), but should be merged after UI changes.
- Ticket 7 can be drafted in parallel once Tickets 1–3 finalize the data model and approval flow.

## Milestones
- Milestone 1: Data model ready (Ticket 1).
- Milestone 2: Draft + per-line submit in settlement UI (Ticket 2).
- Milestone 3: Per-line approval with approval-time effects (Ticket 3).
- Milestone 4: Roll-up statuses and UI visibility aligned (Tickets 4–5).
- Milestone 5: Permissions + regression tests (Tickets 6–7).

## Risks Per Phase
- Phase 1 (Ticket 1): Data migration/backfill may misclassify existing settlements; ensure safe defaults and rollback path. (Completed)
- Phase 2 (Ticket 2): Validation changes may allow incomplete lines to be submitted; ensure submit enforces method-specific rules. (Completed)
- Phase 3 (Ticket 3): Double-posting or race conditions on approvals; must enforce idempotent approval and transactional updates. (Completed)
- Phase 4 (Tickets 4–5): Roll-up statuses can conflict with existing header-level labels or reports; ensure consistent mapping and avoid updating `payment_status` and `settled_at`. (Completed)
- Phase 5 (Tickets 6–7): Permission gaps or missing test coverage may allow unauthorized approvals; ensure server-side Gate checks in all endpoints.

## Testing Strategy
- Database: migration tests/backfill validation for Ticket 1.
- Livewire: component tests for draft save, per-line submit, and read-only states (Ticket 2).
- Feature tests: per-line approval effects for CASH/CREDIT/MODIFY_PURCHASE; rejection resets; idempotency (Ticket 3).
- UI checks: status roll-up rendering and per-line method display in list/print/detail (Tickets 4–5).
- Permissions: explicit tests for approve/submit guards and view-price restrictions (Ticket 6).
- Regression: ensure `payment_status` and `settled_at` remain unchanged on approvals (Ticket 7).
