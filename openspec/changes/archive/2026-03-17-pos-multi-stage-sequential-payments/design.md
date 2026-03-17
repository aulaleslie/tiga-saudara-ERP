## Context

The current POS payment modal (`pos-checkout-modal`) collects multiple payment methods in an array, displays them all at once, and submits them together via a single `POST /pos/sell/checkout/finalize` call. This works for pre-planned multi-payment transactions but creates UX friction for incremental payments (customer says "I'll pay 1M with BRI, then 1M with BNI, then the rest with cash").

The redesign shifts to a **staged, sequential model**:
1. Modal opens with remainder = grand_total
2. User selects ONE payment method and amount
3. Submit → API commits that payment and returns new remainder
4. If remainder > 0, modal resets and user selects next payment (loop back to step 2)
5. If remainder = 0, print receipt in new tab, show gratitude dialog, close modal
6. If browser reloads mid-chain, session state recovers and modal reopens at the correct remainder

This requires:
- New backend endpoint for per-stage payment commits
- Frontend state machine for stage progression and modal lock/unlock
- Session/DB tracking of payment stages for reload recovery
- Modified modal UI (simplified, focused on current stage only)

## Goals / Non-Goals

**Goals:**
- Enable incremental payment entry without pre-selecting all methods upfront
- Provide immediate feedback after each payment stage (remainder recalculated and displayed)
- Show clear visual chain of committed payments so users understand transaction progress
- Support both single-payment (simple) and multi-payment (staged) checkout flows
- Persist stage state across browser reloads to allow graceful recovery mid-transaction
- Validate EDC receipt references for non-cash payments; no external gateway integration for non-cash
- Lock modal UI during processing to prevent accidental double-submissions or cancellations
- Print receipt in new tab after final payment, then show gratitude dialog with change amount

**Non-Goals:**
- Implement external payment gateway integration for non-cash methods (EDC reference is manual entry only)
- Support partial refunds or payment reversal mid-chain
- Add real-time payment status polling from external services
- Redesign receipt printing system (use existing `printReceipt()` function)
- Modify customer or product selection flows
- Support payment plans or deferred payments

## Decisions

### 1. Per-Stage API Endpoint vs. Batch Submission
**Decision:** Create a new `POST /pos/sell/checkout/stage-payment` endpoint that commits ONE payment and returns the remainder, rather than extending the existing `finalize` endpoint.

**Rationale:**
- Each stage is independently committed to the database (atomic, no multi-step rollback needed)
- Remainder calculation happens server-side with accurate committed total
- Easier to track payment order and detect mid-chain failures
- Simpler error recovery: a failed stage doesn't invalidate previous stages

**Alternative considered:** Batch all stages client-side, submit array to existing `finalize` endpoint. Rejected because reload recovery becomes complex (no single source of truth for what actually committed).

### 2. Session-Based Stage Persistence
**Decision:** Store payment chain state in the Laravel session (keyed by transaction ID) so reload recovery can reconstruct the modal state.

**Rationale:**
- Session is per-user, per-browser (natural fit for reload recovery)
- No additional DB schema needed
- State survives page reload but not session expiry (acceptable trade-off)
- Server can verify payment chain integrity on resume

**Alternative considered:** LocalStorage-only (client-side). Rejected because server needs to validate/verify what was committed before allowing next stage.

### 3. Single-Stage Focus vs. Wizard Tabs
**Decision:** Keep a single modal that resets per stage, not a tabbed/multi-step wizard.

**Rationale:**
- Simpler UX: user focuses on ONE payment decision at a time
- Modal resets between stages (clears method search, amount input)
- Payment chain is shown as a simple list above the current stage section
- Reduces visual clutter

**Alternative considered:** Tabbed wizard showing all stages at once. Rejected because it complicates the UI for single-payment case and makes "current stage" less obvious.

### 4. EDC Reference Validation Logic
**Decision:** For non-cash payments, show a second input field after method/amount selection asking for EDC receipt reference (last N digits). Validate format only (no external gateway call).

**Rationale:**
- Non-cash methods integrate via EDC machines, not APIs
- User must manually enter receipt number as proof
- Format validation (e.g., "must be 6 digits") is business logic; actual verification happens in accounting/reconciliation
- CASH payments skip this step (go straight to commit)

**Alternative considered:** Single unified input for all payment types. Rejected because CASH doesn't need a reference, and conditional fields reduce clarity.

### 5. Modal Lock During Processing
**Decision:** When a stage payment is in-flight (pending server response), disable all inputs, hide the close button, and show a loading spinner with message "Processing payment... do not close or reload."

**Rationale:**
- Prevents accidental form submission or cancellation mid-flight
- Clear visual feedback that something is happening
- Enforces single-point-of-no-return (once submitted, stage is committed)

**Alternative considered:** Allow user to cancel mid-flight. Rejected because it introduces async complexity: what if cancel arrives after payment committed on server?

### 6. Reload Recovery Placement
**Decision:** After page reload, JavaScript checks session for in-progress payment chain and automatically re-opens the modal at the correct stage (showing committed payments + remainder) without requiring user action.

**Rationale:**
- Transparent to user: they reload, modal pops back with correct state
- No additional "resume transaction" button needed
- Reduces cognitive load

**Alternative considered:** Show banner with "Resume transaction?" button. Rejected as unnecessary friction.

### 7. Print + Gratitude Flow
**Decision:** After final payment commit, modal stays open with a summary. [Print Receipt] button opens receipt in new tab. After print (or if user dismisses), show a secondary modal: "Jangan lupa ucapkan terima kasih!" with change amount. Clicking OK closes modals and returns to main POS.

**Rationale:**
- Print happens async (new tab), doesn't block modal
- Gratitude message is a separate UI element (not buried in receipt)
- Change amount is clearly displayed
- Natural progression: confirm payment → print → thank customer → done

**Alternative considered:** Print automatically without user interaction. Rejected because user might want to skip or defer printing.

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Session state expires** → user loses payment chain mid-transaction | Sessions are long-lived (30+ min default). If expiry happens, error message guides user to retry. Acceptable: most transactions complete in minutes. |
| **API fails on stage submit** → payment committed on server but response lost | Implement idempotency key in request. Retry the same stage with same key; server returns same response without double-charging. |
| **User closes modal mid-stage** → confusing state recovery | Modal close button is disabled during processing. After processing, user can close; next stage is skipped and remainder carries forward on page reload. |
| **EDC reference validation is format-only** → user enters wrong reference undetected | Acceptable: actual EDC receipt matching is done during reconciliation/accounting. Format validation prevents obvious typos. |
| **Single-payment case becomes slower** (one extra API call) | Minimal impact: payload is small, backend is fast. Alternative (batch endpoint) adds complexity. Trade-off acceptable. |
| **Reload during non-final stage leaves modal open** → user confused about progress | Session stores stage number and remainder. On reload, modal opens at correct stage with previous payments visible. Clear affordance (close to return to POS, proceed to continue). |

## Migration Plan

**Phase 1: Backend & API** (no frontend changes yet)
- Add `stage-payment` endpoint and session state persistence
- Keep existing `finalize` endpoint for backward compatibility

**Phase 2: Frontend Redesign**
- Refactor payment modal JS from "gather all" to "stage loop" state machine
- Add payment chain UI (list of committed payments)
- Add EDC reference input conditional logic
- Add modal lock during processing
- Add reload recovery check on page load

**Phase 3: Testing & Rollout**
- Integration tests for multi-stage flow (single payment, 2-stage, 3+ stages)
- Reload recovery tests
- EDC reference validation tests
- Manual QA on live POS

**Rollback:** If critical issue detected, disable the new flow via feature flag and fall back to existing `finalize` behavior (all payments at once).

## Open Questions

1. **Max payment stages?** Should we enforce a limit (e.g., max 10 stages per transaction) to prevent abuse? Currently no limit.
2. **Session timeout handling?** If session expires mid-chain, should we auto-save payment chain to DB as a backup, or accept the loss?
3. **EDC reference format?** What exactly are the validation rules? (e.g., 6 digits, alphanumeric, max length). Clarify with POS operator.
4. **Remainder rounding?** Should final stage auto-fill remainder amount, or require user to enter it? (Auto-fill is friendlier.)
5. **Overpayment tolerance?** If user overpays (e.g., remainder = 100, pays 1000), should we accept and show change, or reject? Currently accepted (shows change).
