## Context

The split planner already decomposes a captured POS bundle price into a parent residual and fixed component allocations, assigns those amounts to physical owner groups, and reconciles group Sale totals to the customer checkout. During final posting, `InlinePosCheckoutPostingAdapter` persists each owner group's full amount on `sale_details.sub_total` but creates fulfilled `sale_bundle_items` with `price = 0` and `sub_total = 0`, retaining only `informational_item_price`. That deliberately zero-valued persistence conflicts with the established distinction between zero/free customer presentation and non-zero internal owner allocation identity.

The canonical case is a Rp110,000 bundle whose parent is fulfilled by Setting A and whose Rp25,000 component is fulfilled by Setting B. Internal Sales must recognize Rp85,000 for A and Rp25,000 for B. The Setting B `SaleDetail` is a logical carrier with zero parent quantity and unit price but Rp25,000 group subtotal; its attached `SaleBundleItem` must also retain Rp25,000 as the nested component allocation. Revenue consumers must not sum both representations.

Existing specifications already establish that component-only returns are blocked and that a customer returns the bundle through its parent line. This change must preserve that contract while ensuring internal reversal can use immutable original allocations.

## Goals / Non-Goals

**Goals:**

- Persist each fulfilled POS component's captured allocation amount and expanded quantity on its `SaleBundleItem`.
- Preserve exact owner-group and checkout reconciliation using POS minor-unit arithmetic.
- Maintain a clear authority boundary: Sale/SaleDetail totals are revenue; SaleBundleItem commercial values are nested allocation identity.
- Preserve POS-owner-only bundle tax extraction and zero/free customer receipt presentation.
- Confirm whole-bundle returns, reports, atomicity, and idempotency remain correct with non-zero internal component values.

**Non-Goals:**

- Changing configured bundle pricing or component informational-price authoring.
- Making components separately billable or independently returnable.
- Changing Normal Sales, where bundle components intentionally remain non-billable zero-commercial composition rows.
- Changing HPP resolution or treating component revenue allocation as cost.
- Adding schema, POS discounts, or broad report redesign.
- Repairing unrelated failures or running full test suites.

## Decisions

### 1. Carry allocated minor units through to component persistence

The posting adapter will derive `SaleBundleItem.price` and `sub_total` from the planner-authored child allocations already scoped to that owner group. `sub_total` is the exact sum of allocated minor units. Unit `price` is derived consistently from allocation amount and fulfilled quantity without replacing the exact subtotal when division produces a remainder.

This uses the captured transaction snapshot rather than reloading `ProductBundleItem`, `ProductPrice`, or a source owner's product price. Reusing `informational_item_price` directly was rejected because it does not by itself express quantity-scaled or rounding-distributed transaction value.

### 2. Treat component commercial values as nested breakdowns

The owner Sale header and `SaleDetail.sub_total` remain authoritative revenue and payment values. `SaleBundleItem.sub_total` describes how part or all of that detail was allocated to a fulfilled component. Aggregate Sale revenue, tax, payment, and accounting reports must not add bundle-item subtotals to their parent detail.

Persisting only the component amount on the logical carrier detail was rejected because it loses exact component identity when a group contains multiple components. Moving component revenue out of SaleDetail was rejected because it would break established Sale totals and consumers.

### 3. Separate internal persistence from customer presentation

Receipt and transaction-detail builders continue presenting the captured full bundle price on the parent and zero/free components. They must not expose internal `SaleBundleItem.price/sub_total` as additional customer charges.

Hard-coding database component amounts to zero for display safety was rejected because presentation is a consumer concern and destroys transaction audit evidence.

### 4. Preserve owner-specific tax policy

For a PKP POS owner, included tax is calculated only from the subtotal assigned to that POS owner's split group. Every other source-owner bundled allocation remains non-tax. Persisting component amounts must not cause tax to be extracted again at the bundle-item level or from the full customer bundle price.

### 5. Whole-bundle return remains the only return unit

Return eligibility and execution continue accepting bundle returns only through the persisted parent bundle line and block component-only return attempts. Returning one bundle unit proportionally reverses its original parent residual and every original component allocation, restores the complete physical composition to original lineage, and reverses each original HPP snapshot independently. Current bundle definitions, prices, and costs are never reloaded to value the return.

### 6. No historical rewrite

Existing `sale_bundle_items` with zero commercial values remain readable. The corrected persistence applies to new POS postings. No backfill is proposed because historic zero can be ambiguous and cannot always be reconstructed safely from owner-group totals.

## Risks / Trade-offs

- [Existing consumers may sum parent and component subtotals] → Identify touched receipt, return, and report consumers and add focused no-double-count regression tests before changing persistence.
- [Rounded unit price may not multiply exactly to subtotal] → Keep planner-allocated subtotal authoritative and verify decimal/multi-quantity cases in minor units.
- [Customer presentation may expose internal prices] → Assert receipt and transaction detail continue showing components as zero/free.
- [Return code may interpret non-zero component values as independently refundable] → Retain parent-only eligibility checks and cover rejected component-only plus whole-bundle proportional reversal.
- [Retry could duplicate allocation rows] → Exercise the existing idempotency key through real finalize replay and assert row counts and totals remain unchanged.
- [Legacy zero rows differ from new rows] → Avoid backfill and make readers tolerate both while new postings provide stronger evidence.

## Migration Plan

1. Update planner/posting mapping so child allocations retain exact minor-unit commercial values through persistence.
2. Run focused split-bundle posting tests, followed by directly affected receipt, return, HPP/report, atomicity, and idempotency tests.
3. Deploy as an application-only change with no schema migration or data rewrite.
4. Roll back application code if necessary; newly persisted component values remain compatible decimal data and must not be destructively cleared.

## Open Questions

None. Pricing, ownership, tax, receipt, return, and HPP semantics are already settled by the bundle hardening guide and existing specifications.
