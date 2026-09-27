## Context

`PurchaseReceivingCompletionService` performs two independent reconstructions of the final retained lines: one for the user-facing preview and one inside the locked completion transaction. Both currently provide only quantity, price, discount, and tax ID to `PurchaseNormalizer`. The normalizer deliberately treats a missing incoming line tax amount as zero, so a PKP line becomes untaxed when its quantity is reduced.

The persisted purchase-detail amounts are the authoritative document snapshot. Completion must reduce those monetary amounts to the final approved quantity without changing the tax treatment selected when the purchase was created.

## Goals / Non-Goals

**Goals:**

- Make preview and completion calculate identical final PKP monetary values.
- Preserve a retained PKP line's tax ID and proportionally reduced taxable subtotal and tax amount.
- Preserve existing non-PKP stripping, lifecycle eligibility, locking, payment-overage guard, and audit behavior.
- Add focused regression tests covering tax treatment and rounding.

**Non-Goals:**

- Reprice purchases, change tax rates, or alter tax-inclusion policy.
- Recompute historical receipts, stock, product cost, payments, or archived documents.
- Add database fields, migrations, new permissions, or UI controls.

## Decisions

### Use persisted line monetary values as the completion basis

For each retained detail, construct normalizer input from the original persisted line, scaled by `approved_received_quantity / ordered_quantity`: the line subtotal, product tax amount, and pre-tax subtotal. Continue using the existing price, unit price, discount, and tax ID fields.

This preserves the document's original PKP treatment, including tax-included pricing and any source rounding, rather than deriving tax from the current tax master. It also makes a full-quantity retained line unchanged.

Alternative considered: recompute tax from the current `Tax` record and unit price. Rejected because tax rates/settings may have changed since purchase creation and it risks changing historical financial data.

### Centralize completion-line construction

Create a private service helper that returns the final normalizer input for a retained detail and its approved quantity. Both `preview()` and `complete()` call this helper.

Alternative considered: patch each call site separately. Rejected because the preview could again diverge from the atomic write path.

### Continue routing outcomes through PurchaseNormalizer

The helper supplies the prorated monetary inputs, then the existing normalizer produces persisted detail/header values. This retains established PKP/non-PKP behavior and header/payment calculations.

Alternative considered: calculate and write final totals directly in the completion service. Rejected because it duplicates normalization rules and would create a third monetary-calculation path.

### Round at persisted monetary precision

Prorated monetary inputs are rounded to the same two-decimal currency precision used by `PurchaseNormalizer`. Header tax is the sum of finalized line tax values, ensuring that persisted details and header remain reconcilable.

## Risks / Trade-offs

- [Fractional quantity or non-even monetary split leaves a rounding remainder] → Round each retained line through the existing normalizer and assert line/header reconciliation in focused tests.
- [A future edit changes only preview or only completion] → Require both paths to use the shared helper and test preview against the final persisted values.
- [Old data has inconsistent line subtotal/tax values] → Preserve and proportionally scale the stored document values instead of applying a current tax rate or silently rewriting history.
- [Non-PKP purchases accidentally retain residual tax] → Keep the existing normalizer's non-PKP branch and add explicit non-PKP regression coverage.

## Migration Plan

1. Deploy the service and test changes; no migration or backfill is required.
2. New completions use corrected proportional tax calculations.
3. Existing completed documents remain immutable. If historical correction is required, use the established purchase-correction workflow under its existing authorization and audit rules.
4. Rollback is a code rollback; no persisted schema/state must be reverted.

## Open Questions

- None. The source detail values and existing normalizer define the required accounting basis.
