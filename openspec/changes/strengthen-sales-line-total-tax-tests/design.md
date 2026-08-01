## Context

`enhance-sales-tier-price-overrides` delivered Sales Total Baris editing, which reverse-calculates a unit price from a committed final line total. Its calculation matrix tests were written to cover non-PKP, PKP tax-inclusive, PKP tax-exclusive, discount, and invalid-input cases.

Those tests assign a line tax by setting the `product_tax` Livewire property and then calling `updateTax($rowId, $id)`. `ProductCart::updateTax($row_id, $id, $selectedTaxId = null)` resolves the tax from its **third** argument, so the resolved id was always `null` and no tax was ever written onto the cart line. The assertions still passed because in tax-inclusive mode `unit_price = line_total / qty` whether or not tax applies — true statements about an untaxed line, describing themselves as tax coverage.

Two code paths independently decide whether a Sales line is taxed. `calculateSubtotalAndTax()` force-nulls the tax id for non-PKP sale carts; `updateLineTotal()` read the tax rate directly from whatever tax id sat on the line. Because no test ever put a tax on a line, the divergence was unreachable.

## Goals / Non-Goals

**Goals:**

- Make the tax-related line-total tests exercise the real `updateTax` contract, so a regression that drops tax handling fails them.
- State the PKP condition for tax applicability in the Total Baris requirement rather than leaving it implicit in one calculation helper.
- Correct the reverse-calculation divergence the strengthened tests exposed.

**Non-Goals:**

- Changing tax rules, PKP determination, pricing-source semantics, discount handling, or bundle behavior.
- Changing `updateTax`'s signature or how the UI passes a tax selection.
- Revisiting `enhance-sales-tier-price-overrides`, which remains complete and correct as delivered.

## Decisions

### Pass the tax id through the real argument

Affected tests now call `updateTax($rowId, $id, $this->tax11->id)` and set `is_tax_included` explicitly rather than relying on the PKP mount default. Each also asserts `options->product_tax` holds the tax id, so a line that silently loses its tax fails immediately instead of passing on arithmetic that is insensitive to tax.

Alternative considered: change `updateTax` to fall back to `$this->product_tax[$id]` when the third argument is absent, making the original test calls work. Rejected — that changes a production contract to accommodate test call sites, and the property is component state that may lag the committed selection. The tests were wrong, not the signature.

### Assert the full tax decomposition, not just the total

Each strengthened test asserts `product_tax_amount`, `sub_total_before_tax`, and `sub_total`, plus the relation `sub_total == sub_total_before_tax + product_tax_amount`. Asserting only the final total is what let the original tests pass while untaxed: the total alone cannot distinguish a taxed line from an untaxed one in tax-inclusive mode. The decomposition can.

Tax-inclusive extraction at 11% does not divide evenly, so those comparisons use a 0.01 delta; exact cases keep identical comparison.

### Rename tests that cannot carry tax

A non-PKP Sales line can never be taxed. Tests named `..._with_tax_...` and `..._tax_included_...` under a non-PKP setting described a state the system does not permit. They are renamed to `test_line_total_non_pkp_ignores_tax_and_reverses_correctly` and `test_line_total_non_pkp_tax_included_mode_extracts_no_tax`, and now assert the force-null behavior — that an assigned tax yields zero tax amount and a pre-tax subtotal equal to the full total.

Alternative considered: delete them as redundant with the plain non-PKP case. Rejected — the guarantee that a retained tax selection does not leak into a non-PKP line is exactly the defect below, and is worth pinning.

### Align updateLineTotal with calculateSubtotalAndTax

`test_line_total_non_pkp_ignores_tax_and_reverses_correctly` failed on first run: a committed Total Baris of 2500 produced 2252.26, precisely 2500 ÷ 1.11. `updateLineTotal()` divided by the tax rate to strip a tax that `calculateSubtotalAndTax()` then declined to add back.

The fix nulls the tax id in `updateLineTotal()` under the same condition the calculation helper already applies, before the rate is read:

```php
if ($this->cart_instance === 'sale' && ! $this->isPkp) {
    $tax_id = null;
}
```

Alternative considered: relax the force-null in `calculateSubtotalAndTax()` so non-PKP lines can carry tax. Rejected — that inverts a deliberate business rule and would put tax on non-PKP sales documents. The reverse calculation is the side that was wrong.

Alternative considered: clear stale tax selections from cart lines on a PKP→non-PKP business change. Rejected as the primary fix — it narrows one route to the bad state without making the reverse calculation correct, and any missed route silently under-totals again. Worth considering separately as cleanup, not as the correctness guarantee.

## Risks / Trade-offs

- [The guard duplicates a condition expressed in two places] → Accepted for a minimal corrective change; both sites now read identically and the spec states the rule, so a future consolidation into one helper is straightforward.
- [Delta-based tax assertions could mask a small systematic drift] → Bounded at 0.01 and applied only where inclusive extraction is genuinely non-terminating; exact cases stay exact.
- [Other reverse-calculation callers may share the divergence] → `updateLineTotal` is the only path that reverses a committed total from a line tax rate; the wider `Sale|Purchase` suite was run to confirm nothing else depended on the previous behavior.

## Migration Plan

No data, schema, or configuration migration. The fix changes only in-memory cart calculation for non-PKP Sales lines carrying a tax selection, and is safe to deploy and roll back independently.

Already-saved sale details that were under-totalled by this defect are not rewritten by this change. They are identifiable as non-PKP sale details whose stored line total is the entered amount divided by a tax rate; correcting them is a separate data-remediation decision, not part of this change.

## Open Questions

None blocking. Two follow-ups are compatible with this design but out of scope: clearing stale line tax selections on a PKP→non-PKP business change, and tightening the remaining pre-existing `SaleProductCartPkpTaxReconciliationTest` failures observed during the regression check.
