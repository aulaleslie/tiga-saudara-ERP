## Context

`product:normalize-purchase-prices` is a historical repair command for rebuilding `product_prices.last_purchase_price` and `product_prices.average_purchase_price` from received purchase history. Today it calculates one global result per stock-managed product, then writes that result to every setting row.

The runtime purchase approval path already calculates average purchase price from DPP using `PurchaseCostHelper::calculateUnitCost(sub_total, product_tax_amount, product_discount_amount, quantity)` and then synchronizes the resulting average globally through `ProductAveragePriceSynchronizer`. The normalization command does not currently share that DPP cost basis; it uses purchase detail unit price fields directly, which can represent tax-included values for imported taxable purchases.

The business rule for this change is intentionally narrower than a full costing-model redesign. Historical normalization should isolate `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA` into their own average-cost buckets when they have eligible history, while future purchase approval remains globally synchronized.

## Goals / Non-Goals

**Goals:**

- Recalculate historical normalized `average_purchase_price` from DPP unit cost.
- Recalculate historical normalized `last_purchase_price` from the latest eligible DPP unit cost.
- Bucket normalization results into:
  - `CV TIGA NUSA COMPUTER`
  - `CV TOP IT INTERNUSA`
  - REST/global settings
- Fall back from either special-company bucket to the REST/global result when that special bucket has no eligible history.
- Preserve current dry-run behavior and row create/update counts.
- Preserve sales metadata on existing and newly created `product_prices` rows.

**Non-Goals:**

- No change to purchase approval/runtime global average synchronization.
- No schema changes.
- No new costing method for sales snapshots or inventory valuation reports.
- No purchase return adjustments in normalization.
- No change to import tenant-routing rules.

## Decisions

### D1: Use DPP unit cost in the normalizer

For each eligible purchase detail, normalization will calculate unit cost as:

```text
(purchase_details.sub_total - purchase_details.product_tax_amount) / eligible_quantity
```

This same DPP unit cost will feed both weighted average calculations and latest-event `last_purchase_price`.

Rationale: Purchase approval already uses DPP for average cost, and taxable imported purchase detail unit prices can be tax-included. Normalization should repair historical cost baselines using the same DPP basis.

Alternative considered: keep `last_purchase_price` as tax-included while changing only `average_purchase_price`. Rejected because the agreed normalization output should use DPP for both price snapshots.

### D2: Calculate three buckets per product

The command will classify each eligible purchase event by parent purchase setting:

```text
CV TIGA NUSA COMPUTER -> TIGA_NUSA bucket
CV TOP IT INTERNUSA   -> TOP_IT bucket
all other settings    -> REST bucket
```

Each bucket tracks total eligible quantity, total DPP cost, and latest eligible DPP cost event.

Rationale: The two named companies need historical normalization isolated within themselves. The rest of the businesses continue to behave as one global pool for normalization.

Alternative considered: calculate a separate average for every setting. Rejected because the requested split is only for the two named companies; all other settings stay pooled.

### D3: Use REST/global as special-bucket fallback

When a special bucket has no eligible positive quantity for a product, its row will receive the REST/global normalized result if REST/global has eligible history.

Rationale: This avoids blank or stale special-company product price rows for products whose only historical purchases are in the shared/global pool.

Alternative considered: skip the special-company row when its bucket is empty. Rejected because the desired behavior is fallback to rest/global.

### D4: Keep future purchase approval globally synchronized

No changes will be made to `ProductAveragePriceSynchronizer` or the purchase approval path that calls it.

Rationale: This change is a historical normalization repair. Future operational behavior intentionally stays global.

Alternative considered: make runtime approval bucket-aware as well. Rejected because it would change future costing behavior beyond the requested normalization scope.

### D5: Keep setting resolution name-based and explicit

The command will resolve the special settings by company name matching `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA`. Settings that do not match those names belong to REST/global.

Rationale: Existing import ownership behavior and tests use these company names as business identifiers, and the command is an operator repair tool rather than a user-configurable costing engine.

Alternative considered: add configuration or database flags for cost-normalization groups. Rejected because it adds schema/configuration surface for a narrow historical repair.

## Risks / Trade-offs

- [Risk] Company names may differ in production spelling or casing. -> Mitigation: use case-insensitive matching and add tests with the exact expected names.
- [Risk] REST/global may have no eligible history for a product. -> Mitigation: preserve existing skip behavior for products without any eligible bucket result.
- [Risk] Existing tests expect identical normalized costs across every setting. -> Mitigation: update coverage to distinguish special buckets, fallback behavior, and unchanged runtime global synchronization.
- [Risk] DPP calculation using eligible received quantity can differ from purchase detail ordered quantity when tax/subtotal is line-level. -> Mitigation: allocate line DPP proportionally by dividing line DPP by the same eligible quantity used for the event, matching the normalization event quantity basis.

## Migration Plan

1. Update normalization event building to compute DPP unit cost.
2. Resolve special setting IDs/names once before chunk processing.
3. Build per-product bucket summaries while iterating eligible purchase details.
4. Select the target bucket result for each setting row, using REST/global fallback for empty special buckets.
5. Preserve existing dry-run and `--write` behavior.
6. Add focused tests for DPP cost, special-company isolation, REST/global pooling, special fallback, and runtime global sync preservation.

Rollback strategy: revert the command and tests to the previous one-global-result behavior. No schema rollback is required.

## Open Questions

- None. The agreed rule is DPP for both normalized average and last purchase price, special buckets for `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA`, REST/global fallback for empty special buckets, and unchanged future global synchronization.
