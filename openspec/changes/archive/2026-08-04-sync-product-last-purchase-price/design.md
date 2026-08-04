## Context

`product:seed-average-cost-from-sales-hpp` is an explicit, dry-run-by-default reconciliation command. It currently selects the newest positive `HPP_SNAPSHOT_IMPORT` sale-detail cost snapshot per product and established business bucket, then writes only `product_prices.average_purchase_price` in `--write` mode.

Sales imports provide HPP snapshots only for CV Tiga Nusa Computer, CV Top IT Internusa, and Perdana. Literal purchase history is the authoritative source for `last_purchase_price`. Existing purchase normalization establishes useful eligibility and ordering conventions, but its REST/global bucket can allow an unrelated regular business to become the fallback source. This change keeps HPP seeding unchanged while adding a separate literal-purchase resolution path with Perdana as the named default source.

## Goals / Non-Goals

**Goals:**

- Preserve the command's current HPP snapshot candidate selection, bucket behavior, dry-run safety, and `average_purchase_price` values.
- Resolve a last purchase price from the latest eligible literal purchase detail for the same product and target business, falling back to Perdana for that product.
- Calculate the literal purchase unit price as tax-inclusive and discount-excluded: `(sub_total + product_discount_amount) / quantity`.
- Update both values independently in an existing `product_prices` row without changing selling, tier, or tax metadata.
- Create a missing target row only when both the current HPP candidate and literal-purchase candidate required for that target resolve, reusing the existing metadata-template convention.
- Avoid replacing a known last purchase price with zero when no literal purchase candidate exists.

**Non-Goals:**

- Recalculate, reinterpret, or otherwise alter imported HPP snapshots or their average-price selection.
- Change normal purchase approval, purchase import, historical purchase normalization, manual product pricing, or sales-import workflows.
- Change schema, API contracts, product UI, permissions, tax setup, or historical purchase/sale records.
- Treat a purchase detail's DPP cost as its last purchase price for this command.

## Decisions

### Keep average and last price candidates independent

The command will retain its existing imported-sale snapshot candidate for `average_purchase_price`. It will separately load eligible received purchase details to resolve `last_purchase_price`; the two fields need not come from the same document or date.

This preserves the established authoritative HPP-import behavior while making last price represent an actual purchase.

Alternative considered: set both fields to the imported HPP snapshot. Rejected because a sales snapshot is not a literal purchase and can make the displayed last purchase price misleading.

### Literal-purchase eligibility and deterministic recency

Eligible candidates are purchase details for the current product whose parent purchase is non-archived and has status `RECEIVED` or `RECEIVED PARTIALLY`, with a positive quantity. The newest candidate is selected by approved receiving timestamp when an approved receiving note exists, then purchase date, then stable document/detail identifiers.

This reuses the domain's existing received-purchase ordering, so the command does not consider drafted, cancelled, or unreceived purchasing activity to be a last purchase.

Alternative considered: use the newest purchase-header date alone. Rejected because approved receiving time is the stronger indicator that stock and purchase price became effective.

### Tax-inclusive, discount-excluded last-price formula

For the selected purchase detail, calculate the value as:

```text
(sub_total + product_discount_amount) / quantity
```

`sub_total` is treated as the tax-inclusive line total in this business rule. Adding the stored discount removes the effect of the discount without stripping tax. The result is rounded to the `product_prices` monetary precision.

Alternative considered: reuse the DPP helper (`sub_total - tax`) or raw `price`. Rejected because the business rule explicitly requires tax inclusion and discount exclusion, while those values do not consistently represent that final line-level amount.

### Perdana is the explicit default source

Resolve values per target setting as follows:

```text
Target setting
  own eligible literal purchase for product ────────────────┐
  otherwise Perdana eligible literal purchase for product ───┴─► last_purchase_price

  existing own imported-HPP snapshot candidate ─────────────┐
  otherwise Perdana imported-HPP snapshot candidate ─────────┴─► average_purchase_price
```

Tiga Nusa and Top IT continue to prefer their own HPP snapshot, then Perdana. Perdana uses its own candidates. Every other business prefers its own literal purchase for last price, otherwise Perdana; its HPP comes from Perdana because no other sales-import HPP source is authoritative for defaulting.

Alternative considered: keep the generic REST/global fallback. Rejected because any non-special business with a newer purchase could incorrectly become the default source for another business.

### Missing values are non-destructive

If an existing target price row has no own or Perdana literal-purchase candidate, the command may still reconcile its average price but must retain the existing `last_purchase_price`. If a price row is missing and no literal-purchase candidate resolves, it is not created by this command, preventing a fabricated zero or null last purchase price.

Alternative considered: write zero or copy the legacy `products.purchase_price`. Rejected because neither proves a current literal purchase, and zero would overwrite useful data.

## Risks / Trade-offs

- [Older imports may have inconsistent tax/subtotal conventions] → Constrain the new formula to the stated tax-inclusive rule and add fixtures that encode inclusive subtotal plus discount behavior.
- [A setting name may not identify Perdana consistently] → Resolve Perdana by the agreed exact company name in one helper and make an absent/default-setting case safe: no fallback candidate, no destructive write.
- [Products with HPP snapshots but no purchase history will leave last price untouched or omit a missing row] → This is deliberate to preserve truthful last-purchase data; command output and tests should make skipped rows observable.
- [Loading purchase history per product could be expensive] → Batch preload candidates for each product chunk and group them by product and setting/default source, following the existing normalizer's chunked query style.
- [Changing fallback semantics may differ from legacy REST expectations] → Scope the explicit Perdana rule to this HPP reconciliation command only; do not modify purchase normalization.

## Migration Plan

No migration is required. Deploy the command and focused tests, run it first without `--write` to inspect counts and selected candidates, then run `--write` after operator review. Rollback is code-only; no automatic reversal of reconciled price values is required because the command is an explicit maintenance operation and can be rerun after correction.

## Open Questions

None. The business rules for source priority and calculation were decided during exploration.
