## Context

The conversion form renders a read-only `ConsignmentBillingPreviewService` result. That service groups approved receipt allocations by product, unit cost, DPP, tax ID, and tax rate, then uses their DPP and stored tax to create a physically complete, financially active Purchase. `ConsignmentBillingConversionService` saves zero discounts and no explicit `is_tax_included` value. `PurchaseSourceGuard` and model hooks prevent later commercial edits. Purchase create already offers unit price, row total, row and global discounts, and PKP tax controls, but its cart cannot be used as the source of truth for immutable consignment allocation lineage.

The proposed billing editor must preserve the approved source identities and quantities while allowing the payable to match the supplier invoice. Confirmation #3 is a concrete non-PKP example: 9 plus 3 units at Rp4,200,000 each; a Rp10,000 fixed discount per unit on both rows yields Rp50,280,000.

## Goals / Non-Goals

**Goals:**

- Let authorized billing operators adjust only financial terms before conversion and see a recalculated, exact payable.
- Use Purchase create's meanings for fixed and percentage row discounts, global discounts, tax inclusion, and final row total overrides.
- Save the reviewed terms on Purchase header/details and preserve original allocation snapshots, lineage, and an auditable before/after comparison.
- Revalidate current source evidence and the submitted pricing intent under the existing conversion transaction and locks.

**Non-Goals:**

- Editing supplier, product, quantity, receipt lot, or serial assignment; changing stock or receipt costs; or reopening generated Purchases for ordinary edits.
- Shipping, global tax overrides, receiving, returns, or credit notes.
- Replacing ordinary Purchase create's pricing engine or changing its behavior.

## Decisions

### 1. Keep the consignment conversion form as the editing boundary

Render a stable row identifier derived from the commercial group and its allocation IDs. Show the immutable source price/tax alongside editable unit price, discount type/value, tax choice when PKP, and final row total. Include a document discount type/value and PKP tax-included toggle. The browser can recalculate immediately, but the server is authoritative. This keeps source-specific validation and lineage visible. Reusing the general Purchase cart as the conversion form was considered; its mutable product/quantity and cart state make it a poor fit for allocation-backed rows.

### 2. Express edits as pricing intent and calculate the Purchase on the server

The request carries row IDs and the entered financial controls, not trusted totals. A shared pricing calculator produces both the preview response and final conversion values. It applies fixed row discounts per unit or percentage discounts to unit price, validates a 0–100% range and nonnegative effective price, computes each tax-inclusive or tax-exclusive row total to currency precision, and back-solves unit price when a final row total is explicitly overridden. A row-total override remains authoritative through rounding reconciliation. The global discount is applied to the sum of tax-inclusive row totals, as in Purchase create, without changing line tax, and cannot exceed that sum. Header total and due amount come from the same calculation. No-edit submissions must retain the legacy payable exactly.

For PKP, prefill each row's tax choice from its allocation snapshot and default `is_tax_included` to true. Since existing allocation `unit_dpp` is tax exclusive, display a gross unit price that reproduces the existing row total before any edit; handle any cent residual as a row-total reconciliation rather than silently changing the initial payable. Non-PKP forces null line tax, zero tax, and `is_tax_included = false` regardless of submitted controls. The active setting and valid tax options are checked server-side. Purchase create's calculation semantics are the reference; extract reusable pure pricing logic where practical instead of copying Livewire cart state.

### 3. Preserve original evidence separately from final billing terms

Keep receipt allocation and lineage cost, DPP, tax identity, tax amount, quantity, and serialized links immutable. Purchase detail monetary fields store the reviewed unit price, row discount type/amount, chosen tax, tax amount, row subtotal, and pricing source. Purchase header stores global discount fields, `is_tax_included`, tax sum, total and due. A conversion audit payload records each row's original allocation-based amount, selected controls, final amount, and source allocation IDs, plus the document discount. If a selected tax differs from the receipt snapshot, the lineage remains the original evidence and the Purchase detail plus audit records the billing decision. Do not use a Purchase detail update after creation; populate final values during the existing atomic conversion.

### 4. Revalidate intent against a fresh preview under lock

The POST path rebuilds the approved allocation groups after acquiring the existing locks. It rejects missing, duplicate, unknown, or stale row IDs; nonfinite/out-of-range values; disallowed tax IDs; and any source quantity or grouping change. It then recalculates and persists the same totals shown by the preview endpoint. Repeated conversion keeps the current idempotent behavior and never changes a linked Purchase. The audit records the accepted intent and authoritative calculation, while failures follow the existing failure-audit path.

## Risks / Trade-offs

- **PKP gross-price rounding can drift from legacy DPP plus stored tax** → Treat the original row total as the initial authoritative display value and reconcile cents explicitly; test fractional quantities and multiple allocation groups.
- **Header global discount does not reduce line tax in the existing Purchase create behavior** → Match that behavior and label the calculation clearly in the UI; test PKP totals against Purchase create semantics.
- **Source tax and selected invoice tax can differ** → Keep both identities distinguishable in lineage, Purchase detail, and audit; never rewrite receipt evidence.
- **A stale browser preview could create an unexpected payable** → Verify stable row IDs and recompute under lock, returning an actionable stale-preview error.
- **Downstream reports may assume lineage tax equals Purchase tax** → Review affected reconciliation/report paths and use Purchase fields for the payable while lineage remains source evidence.

## Migration Plan

Add only schema fields needed if existing Purchase fields and the JSON audit payload cannot fully hold the accepted intent. Existing consignment Purchases retain their current amounts and guards. Deploy the updated preview and conversion together, then verify focused cases for non-PKP discounts, PKP tax inclusion/selection, row-total overrides, unchanged submissions, stale intent, and rollback. Rollback of the application leaves already converted Purchases immutable and payable from their persisted fields.

## Open Questions

None blocking. The implementation should use the project's existing active-tax policy for selectable PKP taxes and document any tax-option filtering found during the focused code review.
