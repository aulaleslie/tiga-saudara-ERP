# Purchase Return Settlement — Per-Item Approval Requirements

## 1. Overview
Introduce per-item approval for purchase return settlement so approved items can be applied without waiting for unresolved items. Settlement remains per serial for serial-tracked products and per item for non-serial products. Items without a settlement method can remain pending while the return progresses for approved items; no automatic stale threshold is required.

## 2. Goals & Non-goals
Goals
- Enable per-item approval and partial settlement without blocking the full return.
- Allow settlement drafts with missing methods and track pending items separately.
- Preserve accurate financial and inventory impacts per settlement method.
- Provide clear roll-up status visibility and item-level auditability.

Non-goals
- Automating supplier negotiations or dispute workflows.
- Changing accounting policy or introducing new settlement methods.
- Full UI redesign outside settlement and approval screens.
- Automatic approval or auto-settlement rules.
- Per-line receive tracking for PRODUCT_REPAIR/BROKEN_STOCK (planned later).

## 3. Personas
- Returns Staff: enter settlement methods and resolve pending items.
- Finance/AP: approve items and validate financial impacts.
- Warehouse/Receiving: track repair/replacement items and receiving.
- Managers/Auditors: monitor exceptions, approvals, and timing.

## 4. User Journeys
- Draft settlement: staff open "Kelola Penyelesaian", select methods per item/serial, leave some items pending, and save.
- Submit for approval: staff submit lines with selected methods; submitted lines become read-only and appear in the return detail for approval.
- Item approval: approver reviews each submitted line, approves/rejects with notes; approval applies settlement effects and rejection resets the line with a reason.
- Partial settlement: approved lines are applied while pending lines remain open; roll-up shows partial settlement.
- Pending follow-up: pending items remain open; staff update methods and resubmit for approval.
- Repair/broken flow: dispatch uses the existing flow; per-line receive tracking is deferred.

## 5. Functional Requirements
- Settlement entry supports per-serial/per-item lines, captures method, nominal, target purchase where required, and allows saving with some lines missing a method (pending).
- Pending lines are persisted as settlement rows with a null `method`.
- Item approval status and metadata (approved_by/at, rejected_by/at, reason) live on `purchase_return_item_settlements`; no separate approval table.
- Header settlement record is retained on "Simpan Penyelesaian"; its status is derived from item states for roll-up (e.g., Settled Partially, Settled).
- Settlement page supports per-line submit-for-approval; submitting moves the line to pending approval and locks it.
- Submitted lines are read-only on subsequent edits; rejected lines clear selected values, show rejection info, and become editable without preserving rejection history.
- Approve/reject actions are per line and only available when a settlement method is selected and the line is submitted; lines without methods are not approvable.
- Header-level execute is repurposed into per-line approvals on the purchase return detail; approvals execute settlement effects.
- Permissions gate settlement submit, per-line submit for approval, per-line approve/reject, and nominal visibility (view-price).
- Monetary methods (MODIFY_PURCHASE, CREDIT, CASH) apply financial effects and method validation at approval time; PRODUCT_REPAIR and BROKEN_STOCK do not affect `paid_amount`, and totals update incrementally without double posting.
- Settlement approval does not update `payment_status` or `settled_at`; only `purchase_returns.status` changes for roll-up.
- PRODUCT_REPAIR and BROKEN_STOCK will use a separate receive status later; settlement roll-up is based on approval only.
- Pending lines do not block approvals/settlement; no time-based stale automation is required.
- Credit/cash records are adjusted in place as each line is approved rather than creating a new record per batch.
- Header-level payment method is deprecated; settlement method is stored per line and list/print summaries are derived from line methods.

## 6. Non-Functional Requirements
- Data integrity: approval-time stock/financial updates are transactional and idempotent per item.
- Concurrency safety: simultaneous approvals do not overwrite item states or double-post amounts.
- Performance: settlement pages load efficiently for returns with many serials; server-side pagination or lazy loading as needed.
- Auditability: item-level approvals and effects are traceable and exportable for review.
- Backward compatibility: existing settled returns remain readable without migration issues.

## 7. Assumptions
- Purchase returns already have item and serial data with correct receive/purchase links.
- Unpaid/partially paid purchases expose due amounts for modify/credit methods.
- Existing settlement methods remain valid and are not renamed.
- Current role/permission system is available for gating actions.

## 8. Constraints
- Use existing purchase return settlement data structures where possible to avoid breaking reports.
- Do not alter core inventory or accounting rules beyond item-level approval control.
- Maintain existing Livewire-based settlement entry flow as the primary UI surface.

## 9. Open Questions
- None at this time.
