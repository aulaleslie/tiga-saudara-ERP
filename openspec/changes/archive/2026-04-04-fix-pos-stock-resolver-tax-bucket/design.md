## Context

The POS checkout flow uses a two-stage stock validation and decrement pipeline:

1. **Resolver** (`ResolvePosStockAllocationsService::resolve()`) — validates stock availability and produces allocation arrays with `tax_bucket_used` flags
2. **Posting adapter** (`InlinePosCheckoutPostingAdapter::post()`) — consumes allocations and decrements stock

The system tracks stock in two buckets per product-location: `quantity_tax` and `quantity_non_tax` on `product_stocks`. Serial numbers carry their own `tax_id` reflecting which bucket they were received into.

**Current bug (Resolver)**: For serial products, the resolver forces the cart line's `tax_id` onto every serial via `resolvedTaxId`, then validates against the corresponding bucket. Serials at non-PKP branch locations have `tax_id=NULL` and their stock lives in `quantity_non_tax`, but the resolver checks `quantity_tax` because the selling entity's product price has a tax configured. This causes `SERIAL_TAX_STOCK_UNAVAILABLE` despite stock being physically available.

**Current bug (Posting)**: The posting adapter ignores the `tax_bucket_used` flag from allocations. It re-derives the bucket via `$effectiveTaxId = $sourceIsPkp ? ($snapshot['tax_id'] ?? $taxId) : null`, which can mismatch Phase 1 non-tax allocations for taxable lines (the resolver allocated from `quantity_non_tax` but the adapter would decrement `quantity_tax`).

## Goals / Non-Goals

**Goals:**
- Serial product allocation validates against the bucket where stock physically resides (serial's own `tax_id`)
- Non-serial product allocation always prefers `quantity_non_tax` first, then falls back to `quantity_tax`, regardless of line tax
- Posting adapter decrements the exact bucket the resolver allocated from, using `tax_bucket_used`
- No change to pricing, tax calculation, or display — only stock routing

**Non-Goals:**
- Changing how `tax_id` is set on cart lines or ProductPrice
- Modifying the split posting adapter's planner logic (only its stock decrement)
- Adding new API endpoints or UI changes
- Retroactively correcting historical stock discrepancies

## Decisions

### D1: Serial bucket resolution uses serial.tax_id directly

**Decision**: Replace the `resolvedTaxId` expression in `allocateSerialLineUsingAssignedSerials()`:

```
// CURRENT (broken):
$resolvedTaxId = $lineTaxId !== null && $lineTaxId > 0
    ? (int) $lineTaxId
    : ((int) ($record->tax_id ?? 0) > 0 ? (int) $record->tax_id : null);

// FIXED:
$resolvedTaxId = ((int) ($record->tax_id ?? 0) > 0) ? (int) $record->tax_id : null;
```

**Rationale**: The serial's `tax_id` reflects the actual bucket it was received into. The line's `tax_id` is a pricing concern. Stock validation must match physical reality.

**Alternative considered**: Using the source location's `is_pkp` flag to decide bucket. Rejected because `is_pkp` indicates the entity's tax status, not which bucket a specific serial was received into. A PKP entity can hold both tax and non-tax stock.

### D2: Non-serial lines use unified non-tax-first strategy

**Decision**: Replace `allocateNonTaxableLineNonTaxBucketOnly()` usage. All non-serial lines (taxable and non-taxable) go through the same two-phase allocation: Phase 1 scans `quantity_non_tax` across all priority-ordered locations; Phase 2 scans `quantity_tax` if still unfulfilled.

**Rationale**: Currently `allocateTaxableLineBucketFirst()` already does this correctly. Non-taxable lines use `allocateNonTaxableLineNonTaxBucketOnly()` which only scans `quantity_non_tax`. Unifying means non-taxable lines gain the fallback to `quantity_tax`, and the code path is simplified to a single method.

**Alternative considered**: Keep separate methods. Rejected because the only difference is the Phase 2 fallback that non-taxable lines skip, and having a unified path reduces code surface and ensures consistent behavior.

### D3: Posting adapter uses `tax_bucket_used` for stock decrement

**Decision**: Replace the `$effectiveTaxId` derivation in `InlinePosCheckoutPostingAdapter` with a direct read of `tax_bucket_used` from the allocation chunk:

```
// CURRENT (re-derives, can mismatch):
$effectiveTaxId = $sourceIsPkp ? ($snapshot['tax_id'] ?? $taxId) : null;
$stock->quantity_tax -= $chunkQty;  // if effectiveTaxId set

// FIXED:
$taxBucketUsed = (bool) ($chunk['tax_bucket_used'] ?? false);
if ($taxBucketUsed) {
    $stock->quantity_tax -= $chunkQty;
} else {
    $stock->quantity_non_tax -= $chunkQty;
}
```

The `tax_policy_snapshot.tax_id` remains available and continues to be used for `DispatchDetail.tax_id` and `Transaction` records (those reflect the tax applied to the sale, not the source bucket). Only the stock decrement changes.

**Rationale**: The resolver already computes which bucket stock comes from. The posting adapter should consume that decision, not re-derive it with different logic.

## Risks / Trade-offs

- **[Risk] Existing tests assert current (broken) bucket behavior** → Review and update test assertions that verify stock decrement by bucket. Tests should reflect correct bucket behavior.
- **[Risk] SplitPosCheckoutPostingAdapter may have similar re-derivation** → Audit and apply the same `tax_bucket_used` pattern. The split adapter delegates to single-sale posting chunks which likely share the same code path.
- **[Trade-off] Non-taxable lines gaining quantity_tax fallback** → This is intentional. If stock was incorrectly bucketed, the system should still be able to sell it rather than blocking checkout. The tax_policy_snapshot on the allocation records the source for audit.
