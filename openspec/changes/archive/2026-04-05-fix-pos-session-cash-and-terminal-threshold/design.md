## Context

POS sessions display the "Kas" column which shows expected cash when OPEN. Currently, when sessions transition to CLOSED/CLOSING, the view switches to displaying `counted_cash_total` (which is NULL), making the cash figure disappear. Terminal policies define `cash_threshold` limits but these are not displayed in the UI. Non-terminal sessions correctly cannot finalize but this is unclear to users.

**Current Flow:**
- Session OPEN: "Kas" column shows `expected_cash_total`
- Session CLOSED: "Kas" column shows `counted_cash_total` (displays as NULL/0)
- No terminal threshold visibility
- Non-terminal sessions: finalize button disabled but tooltip missing

**Desired Flow:**
- Session OPEN: "Kas" shows `expected_cash_total`
- Session CLOSED/CLOSING/FINALIZED: "Kas" shows `expected_cash_total` (same as OPEN, not NULL)
- Terminal threshold displayed in new column
- Row highlighted if expected_cash > threshold
- Non-terminal sessions have clear tooltip on disabled finalize button

## Goals / Non-Goals

**Goals:**
1. Display terminal cash threshold in sessions list with visual alert (row highlighting when exceeded)
2. Ensure "Kas" column consistently shows `expected_cash_total` across all session states (OPEN, CLOSED, CLOSING, FINALIZED)
3. Clarify that non-terminal sessions do not require finalization

**Non-Goals:**
- Collect cash input in close modal (close = just end session)
- Modify finalize logic or variance approval workflow
- Change terminal policy management or threshold validation
- Support multiple currencies or dynamic threshold configs
- Retroactively fix already-closed sessions with NULL cash values

## Decisions

### Decision 1: Kas Column Displays Expected Cash (Not Counted Cash)
**What should Kas column show?** `expected_cash_total` for ALL session states (OPEN, CLOSED, CLOSING, FINALIZED).

**Rationale:** 
- Expected cash is what should be in the drawer based on opening float + sales
- It's the same for operators and supervisors to reference
- `counted_cash_total` is supervisor's data (filled during finalize), not relevant during close
- Showing consistent value across states avoids confusion about "missing" cash

**Alternatives Considered:**
- Show counted_cash after close: ❌ NULL until finalize, appears as loss of data
- Show different values per state: ❌ Confusing for operators (what should they count toward?)
- Show both columns: ✓ Could work but adds complexity; simpler to stick with expected

### Decision 2: Threshold Display & Alert
**Display location?** Add new "Threshold" column showing `terminal.policy.cash_threshold`. Highlight entire row with `table-warning` class if `expected_cash_total > cash_threshold`.

**Rationale:** 
- Threshold is per-terminal operational limit
- Separate column is clearer than cramming into Metrik (which shows transaction count or variance)
- Row highlighting alerts operator/supervisor to monitor cash level
- Alert applies to OPEN sessions (operational) and CLOSED (review checkpoint)

**Alternatives Considered:**
- Tooltip on Kas value: ❌ Requires hover, easy to miss for busy lists
- Badge overlay: ✓ Visible but visual noise
- Only highlight if exceeded: ❌ Want to surface threshold even when OK for awareness

### Decision 3: Load Terminal Policy in Controller
**When to load terminal.policy?** In PosSessionController.index() eager load `terminal.policy` to avoid N+1 queries.

**Rationale:** View loop iterates over sessions; threshold access happens for each row. Eager load prevents query per row.

**Alternatives Considered:**
- Load in view: ❌ N+1 queries
- Load in service: ❌ Unnecessary layer

### Decision 4: Non-Terminal Sessions Cannot Finalize
**What about finalize for non-terminal?** Keep finalize button disabled with tooltip: "Finalisasi tidak diperlukan untuk sesi tanpa terminal".

**Rationale:** 
- Non-terminal sessions are general till/float management, not tied to specific hardware
- Finalize is a terminal-specific cash reconciliation step
- Close is sufficient for non-terminal—no further approval workflow needed

**Alternatives Considered:**
- Allow finalize for non-terminal: ❌ Semantic mismatch (finalize = terminal reconciliation)
- Auto-finalize on close: ❌ Unclear to user

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| Threshold value not available for terminal-less sessions | Non-terminal sessions won't have threshold display (correct—no terminal policy associated) |
| Row highlighting (table-warning) may be subtle on some themes | Use Bootstrap's standard warning color; ensure sufficient contrast |
| Expected cash != actual cash at close time (cash discrepancies) | This is expected; finalize will reveal actual vs. expected; Kas column shows what should be in drawer |

## Migration Plan

**Deployment:**
1. Deploy view changes (add Threshold column, fix Kas display logic, add tooltip)
2. Verify template logic and CSS rendering
3. No backend service changes required (no breaking changes)
4. No data migration needed

**Rollback:** Revert view changes; pre-existing column logic is unchanged at service layer.

## Open Questions

1. Is the warning row highlight intended for all sessions (OPEN + CLOSED), or only CLOSED/FINALIZED for review?
2. What threshold color/style preference—Bootstrap warning (yellow), or custom?
