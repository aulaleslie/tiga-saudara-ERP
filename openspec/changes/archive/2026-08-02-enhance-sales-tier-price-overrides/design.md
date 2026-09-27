## Context

The standard Sales Create and Edit flows use one session-backed Livewire cart. `ProductCart` resolves a product's setting-scoped sale, Tier 1, and Tier 2 values and currently re-prices every non-bundled line whenever `customerSelected` fires. A line has an editable unit-price control but no editable final line total. The purchase cart already supports reverse calculation from a final line total, including line discount and PKP tax inclusion.

The existing business-selector event changes the Sales cart's selected setting and tax state but deliberately does not reprice cart lines. Product prices, however, are per business. Sales details are deleted and recreated during an edit update, so any decision to preserve a manual price must survive cart hydration and persistence.

## Goals / Non-Goals

**Goals:**

- Let users set either a Sales unit price or final line total and preserve that manual commercial price through customer and draft-business changes.
- Reprice only automatic, non-bundled lines from the selected customer's tier and the effective business's `product_prices` record.
- Make business-specific price absence visible and deterministic by using zero rather than a legacy/global price fallback.
- Preserve existing tax, discount, bundle, cascade, normalization, and edit restrictions.

**Non-Goals:**

- Changing customer scope, customer tiers, ProductPrice maintenance, POS pricing, or Purchase pricing.
- Introducing customer-specific negotiated price catalogs.
- Automatically repricing selected bundle rows.
- Adding a document-level manually editable grand total.

## Decisions

### Persist line pricing provenance

Add a backward-compatible `pricing_source` value to `sale_details`, with `automatic`, `manual_unit_price`, and `manual_line_total` values. Cart options carry the same value while the form is open.

The first committed edit to either input changes the source to a manual value even if it results in the same numeric amount. Both manual values are protected identically by repricing logic; retaining the distinct source makes the origin auditable and permits a future reset-to-automatic action.

Alternative considered: infer manual status by comparing the persisted price to current tier prices. Rejected because catalog prices can change, a manually entered value can equal a tier price, and tax/discount calculations make comparison unreliable.

Existing sale details will be migrated as `manual_unit_price` to avoid silently changing historic or negotiated draft prices after deployment. New cart lines begin as `automatic`.

### Add Sales line-total editing through canonical reverse calculation

Expose a `line_total` cart state and an editable **Total Baris** control, mirroring the Purchase interaction. Treat Total Baris as the final per-line amount after line discount and tax, before global discount and shipping. Reverse the applicable tax and discount to calculate a two-decimal unit price, then run the normal Sales forward calculation and display that canonical result.

Invalid, blank, and negative totals are rejected. A non-zero line total remains invalid with a 100% percentage line discount. The computed line total—not a separately persisted arbitrary total—is saved through the existing `SaleNormalizer` and `SaleService` pipeline.

Alternative considered: persist a separate total override. Rejected because it would create two competing accounting values and make tax, discount, and document normalization inconsistent.

### Use one automatic price resolver for customer and business changes

Centralize the resolution/repricing path for an automatic normal line. It loads only the effective business's `ProductPrice` row and applies current precedence: WHOLESALER uses Tier 1, RESELLER uses Tier 2, and other customers use base sale price followed by existing eligible quantity cascade behavior. Manual and bundled lines bypass this resolver.

On a draft-business change, run this resolver for automatic normal lines against the target setting. On a customer change, run it against the current selected setting. Product additions use it immediately when a customer is already selected.

Alternative considered: retaining `resolveProductPricing` legacy product-column fallback. Rejected for business changes and additions because a missing target row must be explicit rather than silently borrowing a price from another context.

### Missing target price is zero and visible

If a required target-business `ProductPrice` row is absent, an automatic normal line receives zero unit price and recomputed zero line total. Keep the line automatic and issue one consolidated notification naming the affected products and business. This permits a later customer selection or business change to resolve a newly configured price without discarding the line.

Manual lines are never zeroed merely because the selected business has no ProductPrice row.

### Tax context remains a separate calculation concern

Manual unit price remains unchanged across a business move. If PKP or tax-inclusion context changes, recalculate tax-derived subtotal values from that protected unit price. Automatic lines are first repriced and then calculated in the target tax context. Header totals continue to derive from cart lines, global discount, and shipping.

## Risks / Trade-offs

- [Historic manual intent cannot be reconstructed] → Migrate all existing sale details to a protected manual source.
- [Rounding can change a user-entered line total by a small amount] → Use two-decimal canonical forward recalculation and test tax/discount combinations.
- [Multiple absent target-business prices could produce noisy feedback] → Aggregate affected products into one notification per reprice operation.
- [Business switching conflicts with current cross-business requirement wording] → Modify only the Sales behavior; retain Purchase's no-repricing behavior.
- [Bundle behavior is intentionally exceptional] → Explicitly bypass all automatic resolver logic for bundled rows.

## Migration Plan

1. Add the nullable/defaulted sale-detail pricing-source column without rewriting monetary data.
2. Mark existing details as protected manual pricing in a data migration or safe default/backfill.
3. Deploy the cart, persistence, and test changes together so new rows always write provenance.
4. Rollback application code safely because the new column is additive; do not drop the column in an emergency rollback while records using it exist.

## Open Questions

None. A future explicit “reset to customer price” action is compatible with this design but is not required by this change.
