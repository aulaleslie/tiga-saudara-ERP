# Phase 1 - Requirements Brainstorm (Domain-Driven, Scope-Narrowed)

## 1) Rephrased Problem Statement (Accounting + Inventory Terms)
This change is now explicitly narrowed to two behaviors and must not redesign the purchase-return flow itself:
- For serial-number products in source purchase context (especially under `MODIFY_PURCHASE` settlement), returned/removed serials must remain visible and be marked clearly (red), not hidden or physically removed from view.
- For purchase payment adjustment, settlement logic must stop deleting payment rows. Instead, payments must be invalidated so they are not counted in effective paid/settled totals, while preserving full payment history.

Accounting objective:
- Preserve financial evidence (no silent payment loss).
- Keep paid/unpaid math deterministic by summing only valid (active) payments.
- Maintain historical reconstruction capability for both serial and payment timelines.

Inventory objective:
- Preserve serial traceability in UI state, including records affected by modify-purchase return outcomes.

## 2) Clarifying Questions (Focused)

### Scope Locked by Your Direction
These are treated as fixed decisions unless you change them:
- No redesign of overall purchase-return flow.
- Existing settlement modes remain as-is: `REPAIR`, `BROKEN`, `MODIFY_PURCHASE`.
- Primary implementation focus is `MODIFY_PURCHASE`.
- Serial rows should remain visible and marked red instead of disappearing.
- Payment rows should be invalidated (non-counted) instead of auto-deleted.
- In purchase payment list, delete should only be allowed after invalidation (soft-first policy).

Reply format example: `U1:A, P1:A, I1:B, R1:A, A1:A, M1:A`.

### Quick Response Template (Copy/Paste and Fill)
Choose one letter per question.

```text
U1:__ U2:__ U3:__
P1:__ P2:__ P3:__
I1:__ I2:__
R1:__ R2:__
A1:__ A2:__ A3:__
M1:__ M2:__ M3:__
```

### Consolidated Answers (Locked from Your Latest Reply)
- `U1`: Use a red pill marker only for returned serials in source purchase context.
- `U2`: `C` (purchase detail/show only). Edit flow is out-of-scope because approved/received purchases are already gated from edit.
- `U3`: `A` (show `Invalidate` and `Delete`; `Delete` enabled only after invalidation).
- `P1`: Invalidate all impacted payments in `MODIFY_PURCHASE`, aligned with current "remove all" settlement effect.
- `P2`: `A` (invalidated payments excluded from all effective paid/unpaid calculations).
- `P3`: `B` (no reactivation; invalidation is final).
- `I1`: Keep current serial status behavior (serial can remain `RETURNED` in product serial source). In purchase UI, do not remove row; render it with red marker.
- `I2`: `A` as operational scope choice for this phase (no additional selection-policy redesign; only purchase-side color/visibility adjustment).
- `R1`: `C` (invalidation permission follows current payment-delete permission scope).
- `R2`: Hard delete permitted for users with current payment-delete permission, but only after payment has been invalidated.
- `A1`, `A2`, `A3`: Out-of-scope for this release (no reporting/export redesign in this phase).
- `M1`: `A` (backfill existing payment rows to `ACTIVE`).
- `M2`: `C` (no explicit handling for historically deleted payments).
- `M3`: `A` (direct rollout with regression suite).

### A. UI & UX State Semantics
- `U1` For serials affected by modify-purchase return in source purchase screens, preferred visual marker:
  Options:
  A. red text + `RETURNED` badge
  B. red row background + `RETURNED` badge
  C. strikethrough red text + tooltip reason.
- `U2` Where must the red-marked serial visibility be enforced first:
  Options:
  A. purchase detail/show + purchase payment relation views
  B. all purchase screens including edit tables
  C. purchase detail/show only (phase-2 rollout later).
- `U3` For payment list actions on invalidated rows:
  Options:
  A. show both `Invalidate` and `Delete` (delete enabled only after invalidated)
  B. show `Invalidate` only (no delete for normal roles)
  C. keep `Delete`, but convert delete action to invalidate by default.

### B. Payment Lifecycle & Settlement Math
- `P1` In `MODIFY_PURCHASE`, how to select payments to invalidate when settlement reduces effective paid amount:
  Options:
  A. latest-first (LIFO) deterministic invalidation
  B. oldest-first (FIFO) deterministic invalidation
  C. explicit linkage-based invalidation per settlement source.
- `P2` Invalidation effect scope for totals:
  Options:
  A. invalidated excluded from all effective paid/unpaid calculations
  B. excluded only from purchase settlement math
  C. excluded from settlement + payable, but optionally shown in gross reports.
- `P3` Reactivation policy:
  Options:
  A. allow reactivation before period close with permission
  B. no reactivation (invalidation is final)
  C. allow reactivation any time with finance-admin permission.

### C. Inventory & Serial-Number State Transitions
- `I1` Domain/state handling for serials marked red in source purchase:
  Options:
  A. keep existing serial status values; red is UI projection only
  B. enforce status `RETURNED` and map to red UI
  C. add dedicated status for modify-purchase-return serial visibility.
- `I2` Selection behavior for returned/red serials:
  Options:
  A. visible but non-selectable in normal purchase operations
  B. selectable only in `REPAIR` path
  C. selectable with warning.

### D. Permissions & Roles
- `R1` Who can invalidate purchase payments:
  Options:
  A. finance + settlement approver
  B. finance only
  C. anyone with current payment-delete permission.
- `R2` Who can hard-delete after invalidation:
  Options:
  A. super-admin only (emergency/fix path)
  B. finance-admin + super-admin
  C. nobody (invalidate-only lifecycle).

### E. Audit, Reporting, and Historical Reconstruction
- `A1` Minimum invalidation metadata:
  Options:
  A. reason, actor, timestamp, source document, amount delta
  B. actor, timestamp, source document only
  C. store in generic `audits` only (no explicit payment fields).
- `A2` Reporting representation:
  Options:
  A. effective totals by default; gross shown as optional diagnostic
  B. always show gross + effective side-by-side
  C. keep current totals unchanged in reports.
- `A3` Purchase payment list export/print for invalidated payments:
  Options:
  A. include line items with `INVALIDATED` status
  B. include summary count only
  C. exclude from export/print.

### F. Migration & Backward Compatibility
- `M1` Backfill for payment status:
  Options:
  A. migrate existing payment rows to `ACTIVE`
  B. keep nullable status and treat null as active
  C. phased backfill with feature flag.
- `M2` Already deleted historical payments from old behavior:
  Options:
  A. document limitation in release notes/report footnote
  B. create synthetic recovery entries
  C. no explicit handling.
- `M3` Rollout strategy:
  Options:
  A. direct rollout with regression suite
  B. feature-flag per tenant/location
  C. dual-calculation shadow mode before cutover.

## 3) Solution Approaches (Grounded in Existing Patterns)

### Approach A - Status-Based Payment Invalidation + Serial UI Preservation (Recommended)
#### Conceptual Model
- Introduce/standardize payment lifecycle status (`ACTIVE`, `INVALIDATED`) for purchase payment rows involved in modify-purchase settlement adjustments.
- Settlement recalculation invalidates eligible payments instead of deleting rows.
- Effective totals (`paid`, `due`, `payment_status`) include only `ACTIVE` payments.
- Serial records affected by return/modify flow remain visible in source purchase contexts and are rendered with red marker + returned badge.

#### Pros
- Directly aligns with your stated target behavior.
- Minimal disruption to existing settlement modes (`REPAIR`, `BROKEN`, `MODIFY_PURCHASE`) because only deletion behavior changes.
- Preserves audit trail and keeps historical payment/serial evidence intact.
- Reuses status-driven pattern already common in repo workflows.

#### Cons
- Requires consistent updates across all payment sum queries.
- Needs UI updates across serial rendering and payment action controls.
- Requires clear permission boundaries to avoid accidental hard deletion.

#### Accounting Correctness Implications
- Strong: financial history is preserved and effective totals remain explainable.
- Determinism depends on fixed invalidation ordering/link rule.

#### Data Model Impact
- Payment status + invalidation metadata fields on purchase payment table(s).
- Optional source linkage fields for settlement origin.
- No mandatory serial schema change if red rendering is UI projection.

#### UI Impact
- Source purchase serial list: returned serials remain shown and marked red.
- Purchase payment list: explicit invalidation status/action; delete gated behind invalidated state (if enabled).

#### Risk & Complexity
- Medium.
- Main risk: missing a legacy query that still counts invalidated rows.

#### Testing Burden
- High but focused.
- Requires unit + feature coverage for settlement math, payment list behavior, and serial rendering.

### Approach B - Invalidation Ledger Table (Without Payment Status Column)
#### Conceptual Model
- Keep payment rows unchanged.
- Add mapping table to mark excluded/non-counted payments.
- Totals derive from joins excluding mapped rows.

#### Pros
- Avoids direct schema changes on existing payment table.
- Keeps raw payment rows intact.

#### Cons
- Higher query complexity and easier to miss in read paths.
- Harder for UI to show state without join context.

#### Accounting Correctness Implications
- Acceptable if every calculation path consistently applies exclusion mapping.
- Fragile if any consumer omits exclusion join.

#### Data Model Impact
- New exclusion table and metadata columns.

#### UI Impact
- Additional backend composition needed to display invalidation status in payment list.

#### Risk & Complexity
- Medium-high.

#### Testing Burden
- High, with broad query-path regression burden.

### Approach C - Hard Delete With Mandatory Archive Snapshot
#### Conceptual Model
- Continue deletion behavior but write snapshot/archive event before delete.

#### Pros
- Smaller change to existing calculation queries.

#### Cons
- Conflicts with your preferred non-destructive behavior.
- Still removes live row lineage and increases audit reconstruction friction.

#### Accounting Correctness Implications
- Weaker than invalidation model because evidence becomes indirect.

#### Data Model Impact
- Archive table/event additions.

#### UI Impact
- Less change than A, but does not satisfy "keep row visible and non-counted."

#### Risk & Complexity
- Medium, but wrong fit for business direction.

#### Testing Burden
- Medium.

## 4) Recommendation
Recommend **Approach A (Status-Based Payment Invalidation + Serial UI Preservation)**.

### Why this best fits your current direction
- It implements exactly the requested behavior without redesigning purchase-return flow.
- It keeps current settlement mode structure intact and focuses change where needed (`MODIFY_PURCHASE` side effects).
- It preserves auditability while keeping paid/unpaid logic understandable: only active payments count.
- It supports source purchase serial traceability via visible red-marked records.

### Fallback if constraints change
1. If payment table schema changes are blocked, use Approach B as temporary bridge.
2. Avoid Approach C unless a strict technical constraint prevents invalidation model adoption.

## 5) Open Decisions (Now Minimal)
No blocking open decisions remain for Phase 2 drafting.

Non-blocking cautions to carry into frozen requirements:
1. Broad permission model (`R1` + `R2`) increases misuse risk; enforce explicit action logging in implementation/tests even if audit-report redesign is out-of-scope.
2. Because `A1/A2/A3` are out-of-scope, Phase 2 should explicitly state "no report/export format changes" to avoid accidental scope creep.

## 6) Explicit Assumptions (for next phase drafting)
1. No structural change to purchase-return business flow is in scope.
2. `REPAIR`, `BROKEN`, and `MODIFY_PURCHASE` modes remain available and unchanged in intent.
3. For this change, behavior focus is `MODIFY_PURCHASE` impacts on serial visibility and payment treatment.
4. Payment rows must not be auto-deleted by settlement recalculation.
5. Invalidated payments are non-counted in effective paid/unpaid math.
6. Serials affected by return/modify should remain visible and be red-marked in source purchase UI.
7. Hard delete, if retained, is secondary and gated behind explicit control.

---
Awaiting your selected options to freeze requirements in Phase 2.
