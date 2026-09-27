## Context

POS split posting decomposes selected bundle revenue into parent residual revenue and component allocation revenue. The current planner reconciles owner group totals correctly, but then derives the owner group parent line unit price from the full group subtotal. When a group owns both one parent serial and multiple bundle components, this inflates the parent sale detail "Harga Satuan" even though the sale header total remains correct.

The observed transaction has two bundle parent units at 6,100,000 each and one component priced at 15,000 per bundle. Split posting correctly decomposes 12,200,000 into 12,170,000 parent residual and 30,000 component value, but sale 2 stores parent unit price as 6,115,000 instead of 6,085,000. Product replacement later uses the POS return line total of 6,100,000, so the generated replacement sale also diverges from the replaced source sale detail commercial amount.

Relevant current paths:

- Split planning decomposes bundle totals in `PosCheckoutSplitPlannerService`.
- Inline posting persists group line `unit_price` into `sale_details.price` and `sale_details.unit_price`.
- Approval preview plans replacement valuation in `PosReturnApprovalPreviewPlannerService`.
- Approval plan persistence stores replacement valuation in Sales Return detail `execution_context`.
- Lifecycle execution creates cross-owner replacement Sales from `generated_replacement_sale_effects.payment_amount`.

## Goals / Non-Goals

**Goals:**

- Keep POS split group totals, sale header totals, payment totals, and bundle component row totals reconciled to the checkout total.
- Persist bundled parent sale detail `price` and `unit_price` as the parent residual commercial unit amount for that owner group.
- Allow `sale_details.sub_total` and `sales.total_amount` to include separately persisted bundle component value when that owner group owns the component allocation.
- Value all POS bundled product replacement paths from the source sale detail commercial amount, not the original POS bundle list price.
- Ensure preview, Sales Return detail execution context, original sale correction amount, generated replacement Sale, replacement Sale detail, and replacement Sale payment use one consistent valuation source.
- Add focused tests that reproduce the 6,085,000 / 6,115,000 / 6,085,000 expectation.

**Non-Goals:**

- Do not change customer-facing POS checkout totals.
- Do not remove or hide `sale_bundle_items`; component rows remain needed for audit, return mapping, and stock/component context.
- Do not introduce a data migration to rewrite historical Sales. Historical correction can be handled separately if needed.
- Do not change same-SKU replacement identity rules, serial ownership rules, or cross-owner replacement atomicity.
- Do not redesign Sales display templates beyond making their persisted values correct.

## Decisions

### Decision 1: Carry parent residual unit price separately from owner group subtotal

Split planning should keep two monetary concepts on grouped bundle parent lines:

- `line_subtotal`: the full owner group amount persisted as `sale_details.sub_total` and reconciled into `sales.total_amount`.
- parent residual unit amount: the amount persisted as `sale_details.price` and `sale_details.unit_price`.

For a grouped line with parent quantity greater than zero, parent residual unit amount should be derived from that group's parent residual share divided by parent quantity. It must not include component allocation amounts.

Alternative considered: make `sale_details.price * quantity == sale_details.sub_total` for all split bundle rows. Rejected because the existing POS split model intentionally represents component allocation value separately while still using a parent sale detail row as the owner group line.

### Decision 2: Keep component value in component rows and group totals

The group that owns the component allocation should continue to receive that component value in `sales.total_amount`, `sale_details.sub_total`, `pos_checkout_sales.grand_total`, payment allocation, and `sale_bundle_items.sub_total`. This preserves checkout reconciliation and component auditability.

Alternative considered: remove component value from sale totals and make components informational only for POS split posting. Rejected because current POS split posting explicitly allocates selected bundle component revenue to component owners.

### Decision 3: Resolve replacement valuation from source sale detail commercial amount

Approval preview should compute a canonical replacement commercial amount for bundled product replacement lines. For bundled source sale details, this amount should be the source `sale_details.price` or `unit_price` multiplied by returned quantity, capped/normalized to the returned source sale detail commercial amount. For non-bundled lines, existing line amount behavior may remain unless the source sale detail provides a more precise amount.

The computed amount should feed:

- preview `amount` for the parent replacement detail,
- `original_sale_correction_amount`,
- `generated_replacement_sale_effects.payment_amount`,
- Sales Return detail `sub_total`,
- replacement-owner Sale `total_amount`, `paid_amount`, Sale detail `price`, `unit_price`, `sub_total`, and SalePayment amount.

Alternative considered: continue using `pos_return_lines.line_total`. Rejected because POS return snapshots can contain the original POS bundle list price, which is not the owner-specific sale detail commercial amount after split decomposition.

### Decision 4: Make same-owner and cross-owner replacement valuation consistent

Same-owner replacement may not create a new Sale, but preview, persisted execution context, audit amount, and any future same-owner valuation display should use the same source sale detail commercial amount as cross-owner replacement.

Alternative considered: only change cross-owner generated Sale creation. Rejected because that would leave preview/audit inconsistent and could cause future same-owner reporting to diverge.

### Decision 5: Keep implementation additive and localized

The implementation should update the grouped line payload and return approval planning metadata without changing table schemas. Existing `sale_details`, `sale_bundle_items`, and JSON execution context fields are sufficient.

Alternative considered: add new columns for parent residual unit price and replacement valuation. Rejected because the required persisted fields already exist and the issue is incorrect source selection, not missing storage.

## Risks / Trade-offs

- Existing code may assume `sale_details.price * quantity == sale_details.sub_total` for all rows. Mitigation: add focused tests around Sales show/print expectations and search/report surfaces that read these fields.
- Historical rows will remain with inflated unit price. Mitigation: keep this change forward-looking and document that historical correction is out of scope.
- POS return quantity and replacement valuation may diverge when partial bundled returns are introduced. Mitigation: compute replacement valuation proportionally from source sale detail commercial amount and returned quantity.
- Cross-owner replacement payment reconciliation may change from the previous POS bundle list price. Mitigation: assert generated Sale total, SalePayment, and Sale detail all match the same canonical amount.
- Tax-included owner groups may have parent residual values that include extracted tax behavior. Mitigation: preserve existing tax calculation and only separate parent residual amount from component allocation amount.
