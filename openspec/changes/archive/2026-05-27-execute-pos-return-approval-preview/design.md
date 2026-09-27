## Context

The existing POS Return workflow can create pending returns from POS transactions and render an approval preview that safely maps selected return intent to owner/sale/location/tax-aligned execution targets. That preview is intentionally non-mutating today. Existing lifecycle code has separate approve, receive, cash settlement, and replacement dispatch phases, while the desired workflow is a single final approval execution from the preview page.

The affected domain spans POS Return, linked Sales Return records, original Sale documents, dispatch details, ProductStock, ProductSerialNumber, mutation transaction rows, Sale payments, Sale Return payments, and Sale serial display. The system must preserve the brownfield Laravel/module patterns, existing POS split-owner behavior, bundle lineage, serial tracking, and audit history. All final approval effects must be atomic: if any stock, serial, payment, Sale, dispatch, or Sales Return mutation fails, no mutation should persist.

Current Sale serial display already marks returned serials red through `SaleSerialDisplayResolver` using `sales_order_serial_tracking.return_date` and Sales Return history. Sale payments do not yet have purchase-style invalidation metadata, while Purchase payments already support active/invalidated state and source attribution.

## Goals / Non-Goals

**Goals:**
- Add a final approve action on the approval preview page when the planner reports zero blockers and zero warnings.
- Execute pending POS Returns completely in one transaction using `pos.returns.approve`.
- Persist or synchronize linked Sales Return headers/details from the preview plan before execution.
- Complete stock receiving, serial return tracking, cash-return Sale modification/payment adjustment, replacement dispatch, linked Sales Return completion, and POS Return completion in one atomic operation.
- Modify original Sales for cash returns so the active Sale document reflects the corrected commercial outcome.
- Preserve replacement Sales commercially while showing original returned serials red and replacement serials blue.
- Mirror original bundle inventory movement for parent and component products; component-only returns remain disallowed.
- Add Sale payment invalidation/splitting support equivalent to Purchase payment invalidation.

**Non-Goals:**
- Rewriting historical POS transaction or checkout records.
- Adding a manual Sale payment invalidation UI as part of this change.
- Allowing component-only bundle returns.
- Adding admin override for approval warnings.
- Changing POS Return lookup, draft submission, or edit behavior except where final approval depends on the existing persisted return intent.

## Decisions

### Final approval executes the complete lifecycle

Final approval from the preview page SHALL validate the latest preview plan and then execute every required lifecycle effect in one database transaction. POS Return and linked Sales Return records end as completed when execution succeeds.

Rationale: the user’s approval intent is to persist the preview and finish the return, not to route through separate receiving, settlement, or dispatch actions. Keeping the operation atomic avoids partially corrected Sales, serials, or payments.

Alternatives considered:
- Keep separate receive/settle/dispatch phases. Rejected because it does not meet the desired one-click approval behavior.
- Execute only approval and leave downstream actions pending. Rejected because the original Sale would remain uncorrected after approval.

### Preview warnings block final approval

The final approve control SHALL be enabled only when the preview status has zero blockers and zero warnings. Informational notes, such as “no linked Sales Returns but targets are derivable,” may remain non-blocking.

Rationale: final execution mutates stock, serials, payments, dispatches, and Sales. Ambiguous warning states are too risky to execute.

Alternatives considered:
- Allow approval with warnings. Rejected due to mutation risk.
- Add admin override. Rejected to keep the first execution version deterministic and auditable.

### Approval persists execution targets from the planner

If linked Sales Returns do not already exist, approval SHALL create owner/sale/location/tax-aligned `sale_returns` and `sale_return_details` from the ready preview plan. If links exist, approval SHALL validate that they still match the plan before execution.

Rationale: preview can safely derive targets from POS Return lines and source state. Approval should not require a separate hidden pre-generation step.

Alternatives considered:
- Require pre-existing linked Sales Returns. Rejected because current preview treats missing links as informational when targets are derivable.
- Delete and recreate links every time. Rejected because it is harder to audit and risks losing existing references.

### Cash return modifies the original Sale

Cash-return lines SHALL reduce original customer-facing Sale detail quantities and amounts, reduce active dispatch quantities, adjust Sale payment state, create Sale Return Payment refund evidence, and archive the Sale as returned when both customer-facing Sale quantity and active dispatch quantity are zero.

Rationale: the business goal is to make the original Sale read as corrected, similar to Purchase Return `MODIFY_PURCHASE`, while preserving return audit trails.

Alternatives considered:
- Keep original Sale unchanged and rely only on Sales Return documents. Rejected because the active Sale would continue to look commercially wrong.
- Add negative Sale lines. Rejected because existing purchase behavior edits source documents and because negative rows would complicate POS/sale reporting.

### Sale payment invalidation mirrors Purchase payment invalidation

Sale payments SHALL gain active/invalidated state and invalidation source metadata. When a cash return reduces a Sale total below active paid amount, active Sale payments SHALL be invalidated/split so active payments sum to the new paid amount and surplus is represented by refund evidence.

Rationale: Purchase payments already have the required model. Mirroring it keeps payment correction auditable and avoids header/payment mismatch.

Alternatives considered:
- Mutate existing payment amounts in place. Rejected because it loses the original payment audit trail.
- Add negative refund payment rows only. Rejected because existing Sale payment reports may not consistently handle negative payments.

### Dispatch serial display separates active quantity from historical serial identity

Cash-return dispatch quantities SHALL represent active outbound quantity after return, while historical serial identity remains available for Sale display. Returned original serials remain red. Replacement dispatches add replacement serials shown blue.

Rationale: the Sale document must show both corrected active quantity and serial lineage. Existing dispatch serial JSON/display should not be treated as the only source of active quantity after returns.

Alternatives considered:
- Remove returned serials from all Sale display. Rejected because users need serial lineage in the Sale document.
- Keep dispatch quantity unchanged for cash returns. Rejected because full cash returns must be able to reach “no out” and archive.

### Product replacement preserves Sale money and quantity

Product-replacement lines SHALL receive returned goods/serials, keep original Sale detail money and quantity unchanged, leave original dispatch row visible, create an approved replacement dispatch on the same Sale, and mark replacement serial lineage blue.

Rationale: replacement corrects fulfillment, not the commercial Sale. The customer-facing sale remains valid, while stock and serial identities change.

Alternatives considered:
- Reduce the original line then add a replacement Sale line. Rejected because it creates unnecessary commercial churn.
- Swap serials in the original dispatch row. Rejected because it obscures the original returned serial.

### Bundle execution is resolution-sensitive

Bundle returns SHALL be selected only through the parent bundle line. Cash-return approval SHALL automatically execute proportional parent and component reversals for the cash-returned parent quantity, including split-owner component Sales when the POS transaction posted components to different owner/sale records. Component reversals are not stock-only: when a component belongs to another source Sale or owner, that component Sale, dispatch, payment, and refund evidence SHALL be proportionally corrected from the component movement/value source even when the component's customer-facing `sale_details.quantity` is zero. Returned original serials SHALL remain visible on the source Sale as returned/red while active quantities are reduced. Product-replacement approval SHALL receive the returned parent product and dispatch only the parent replacement product from the original parent owner/location; bundle components remain read-only composition context and MUST NOT create replacement Sale Return details, replacement dispatch details, stock mutations, or Sale/payment adjustments.

Rationale: cash/refund corrects the commercial outcome of the original POS bundle sale and therefore must reverse the proportional parent and component sales across split owners. Some split-owner component postings are represented by zero-quantity Sale detail placeholders with actual movement/value context in bundle or dispatch rows; treating those rows as non-returnable leaves the other owner's Sale, payment, and dispatch wrong. Product replacement corrects fulfillment of the selected parent item while preserving the original commercial sale; replacing component items would create extra fulfillment movement that the business does not perform.

Alternatives considered:
- Always move parent stock only. Rejected for cash returns because original bundle components and split-owner component Sales must be reversed when money is refunded.
- Always move parent and component stock. Rejected for product replacement because bundle components are not replaced; only the parent product is received and dispatched.
- Allow component-only returns. Rejected because POS user intent and Sale display are parent-bundle based.

## Risks / Trade-offs

- Sale payment invalidation schema expands a mature module. → Keep it additive and compatible with existing rows by defaulting old payments to active.
- Dispatch detail `dispatched_quantity` may no longer equal count of historical serials shown. → Document and test active quantity semantics; derive serial badge state from tracking/lineage instead of count assumptions.
- One-click execution has a large blast radius. → Wrap all effects in one transaction and use row locks for POS Return, linked Sales, dispatch details, product stocks, serials, and payments.
- Bundle execution is complex across split-owner sales. → Reuse the approval preview plan as the source of truth; require component mapping for cash-return reversals, including component Sale/payment/dispatch correction, but keep product-replacement component rows informational only.
- Sale archival may hide source documents unexpectedly. → Archive only when customer-facing Sale quantities and active dispatch quantities are both zero, and append an audit note referencing the POS Return/Sales Return.
- Mixed cash-return and replacement returns can affect the same Sale in different ways. → Execute per line resolution while grouping locks and recalculations per source Sale.

## Migration Plan

1. Add nullable/default-compatible Sale payment invalidation columns mirroring Purchase payment invalidation: status, invalidated_at, invalidated_by, invalidation_source, and invalidation_source_id.
2. Add minimal replacement dispatch lineage metadata needed to identify POS Return replacement dispatches and replacement serials for blue display. Prefer additive nullable columns or existing metadata fields over rewriting historical dispatch rows.
3. Backfill existing Sale payments as active without invalidating historical payments.
4. Deploy code that reads both legacy and new payment rows safely.
5. Rollback strategy: schema `down()` methods remove new nullable columns/indexes in dependency-safe order; no rollback should attempt to undo already-executed return corrections.

## Open Questions

- Exact field names for replacement dispatch lineage should be chosen during implementation after checking current dispatch table column availability.
- Sale payment datatable should at minimum hide or badge invalidated payments; full manual invalidation UI is intentionally out of scope.
