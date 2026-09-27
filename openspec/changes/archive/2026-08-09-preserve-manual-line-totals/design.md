## Context

Purchase and Sales each expose `Total Baris` on standard cart rows. Their current handlers reverse the requested total to a unit price, round that unit price to two decimals, and run the normal unit-price recalculation. Because purchase and sale detail `price`/`unit_price` columns have a scale of two decimals, the rebuilt subtotal can differ from the user's committed amount whenever the effective per-unit amount is non-terminating at that precision.

The row subtotal and tax metadata are consumed as monetary authorities by Purchase/Sales create and edit persistence, document totals, payment calculations, and reporting. Sales already identifies total-edited rows as `manual_line_total`; Purchase has no equivalent persisted pricing-source field.

## Goals / Non-Goals

**Goals:**

- Preserve every valid manually committed standard-row total to two monetary decimals exactly in Purchase and Sales.
- Keep row tax allocation internally consistent: `sub_total_before_tax + product_tax_amount = sub_total`.
- Preserve no-tax behavior for non-PKP contexts and cover PKP tax-included and tax-exclusive modes.
- Maintain existing bundled-row restrictions and existing validation for invalid and 100%-discount totals.

**Non-Goals:**

- Increasing database unit-price precision or migrating historical details.
- Changing automatic pricing, quantity-pricing, bundle pricing, global discounts, shipping, or tax-rate rules.
- Making a manually entered line total invariant after a later user-driven quantity, discount, tax, or tax-mode change; those existing recalculation events continue to derive a new total from the retained manual unit price.

## Decisions

### Treat the committed total as the monetary authority for a total-edit event

After validation and reverse derivation, each cart handler SHALL write the user-entered two-decimal final total back to the cart row's `sub_total`, rather than accepting the total produced by multiplying the rounded unit price. It SHALL calculate and store the pre-tax subtotal and tax amount directly from that authoritative total.

This accommodates values such as Rp1.460.000 / 1.200, whose derived unit price is Rp1.216,666..., while retaining a two-decimal unit-price value for display and existing schema compatibility.

Alternative considered: preserve the full fractional unit price. Rejected because the existing database columns are scale-two and persistence would reintroduce drift. Alternative considered: reject totals that do not divide evenly. Rejected because users legitimately enter negotiated document totals and the requested total is the financial commitment.

### Allocate tax from the authoritative final total

For a PKP row with an applicable tax rate, `Total Baris` is always the final tax-inclusive (gross) amount entered by the user. This remains true whether the document tax setting is tax-included or tax-exclusive. Tax mode affects unit-price/DPP derivation and presentation; it SHALL NOT cause tax to be added on top of the committed Total Baris. Calculate the final split from the committed total and round the monetary components to two decimals. The tax amount is the residual needed to make the components sum exactly to the committed line total.

- Tax-included: derive DPP from `total / (1 + rate)`; tax is `total - DPP`.
- Tax-exclusive current line-total workflow: derive DPP from `total / (1 + rate)`; tax is `total - DPP`, because the entered `Total Baris` is the final amount in this workflow.
- No applicable tax, including all non-PKP Sales rows: DPP equals total and tax equals zero.

The derived base unit price continues to reverse discount and tax as today, then rounds to two decimals. It is not used to overwrite the authoritative total in the same edit event.

Alternative considered: calculate tax from rounded unit price times quantity. Rejected because it repeats the precision loss and can make the tax split fail to reconcile to the committed total.

### Preserve existing downstream authorities

Both document forms already persist `sub_total` and `product_tax_amount` from cart metadata, and their edit forms hydrate those fields back into cart metadata. The change will retain that contract: no new database field or schema migration is needed. Sales will continue marking a total edit as `manual_line_total`; Purchase will preserve the requested monetary metadata in the cart without introducing an unrelated pricing-source migration.

The implementation must verify the full stored-document path: an existing Purchase or Sale is opened for editing, its Total Baris is changed, the document is saved, and a fresh edit load retains the exact authoritative total and reconciled tax split.

## Risks / Trade-offs

- [Displayed unit price multiplied by quantity can differ slightly from the authoritative total] → Clearly preserve `sub_total` as the persisted/document authority and add tests proving its exactness.
- [Tax allocations can gain a one-cent residual due to rounding] → Round one component to two decimals and calculate the other as the exact two-decimal residual from the committed total.
- [A later recalculation could accidentally overwrite a manually committed total] → Scope tests to the total-edit event and retain existing intentional rules for later quantity/discount/tax changes; inspect persistence paths in implementation tests.
- [Purchase and Sales logic could drift] → Use matching calculation cases and parallel regression tests for no-tax, tax-included, and tax-exclusive workflows.

## Migration Plan

1. Deploy application changes and focused tests; no database migration or backfill is required.
2. New manually edited documents retain exact row totals immediately.
3. Existing documents remain untouched. Rollback consists of reverting the application change; no persisted-schema rollback is needed.

## Open Questions

None. The existing workflow establishes that `Total Baris` is a final, tax-inclusive row amount even in the tax-exclusive UI mode; this change preserves that behavior while eliminating rounding drift.
