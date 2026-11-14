# Design

## Context

See proposal.md, "Why". Production data shape (sale 215753):

```
sale_details 432029  product 4433 (laptop) qty 1, serial 424, dispatch 433368
  sale_bundle_items 1730/1731/1732 -> sale_detail_id 432029
    products 192 / 318 / 1607, dispatches 433369 / 433370 / 433371
(no component sale_details rows)
```

`PosBundleComponentResolver::resolveOriginalBundleContext()` resolves all three components with `carrier_sale_detail_id = 432029` and `lineage_status = resolved`. Today the following code assumes a component line always sits on a different `sale_detail_id` from the parent:

- **Synthesis:** `PosReturnSubmissionService` L1652-1670 skips a component when its carrier is the parent row.
- **Quantity guard:** `PosReturnQuantityGuard` L107-153 counts by `sale_detail_id` only, and `getReturnableQuantity` falls back to the same key.
- **Component detection:** done with `bundle_parent_sale_detail_id != sale_detail_id` in Submission (L945, L981-983, L1131-1135, L1272-1277) and the Planner (L384, L441, L1376).
- **Draft resubmit:** sends `__component_product_id = null`, so the dedupe at L1924 retags a component line as a parent line.
- **Line amounts:** `line_total` is `quantity * carrier.unit_price` (L215/L250), so on the parent row a component would show the laptop price.

Approval-time stock dispatch already resolves through the line's `dispatch_detail_id` and `product_id` (Planner L1249-1300, `dispatchMatchesLine`).

## Goals / Non-Goals

**Goals:** one synthesis path for both carrier shapes; component identity that never depends on the carrier row id; quantity accounting per (row, product).

**Non-Goals:** whole-bundle replacement synthesis, parent-only behaviour, UI changes, data backfill, schema migration.

## Decisions

1. **Synthesis uses the resolver's components directly.** For each `bundleContext['components']` entry, the carrier is `carrier_sale_detail_id`, the dispatch is `dispatch_detail_id` and the product is `product_id`. This replaces `findComponentBundleItem` + the sibling lookup for cash returns.
   - *Alternative rejected:* keep the sibling lookup and fall back to the resolver. That leaves two paths, and the resolver already handles sibling carriers.
   - The replacement-mode branch keeps its current filter, so only the components it chose are synthesized.
2. **Component identity is a single helper.** `PosReturnLine::isComponentLine(array|PosReturnLine)`:
   - returns true when `component_quantity_per_bundle > 0`;
   - otherwise returns true when a bundle parent id is set and `product_id` differs from the carrier row's `product_id`.

   Every synthesized component sets `component_quantity_per_bundle`.
   - *Alternative rejected:* a new `is_bundle_component` column. It needs a migration, and existing rows already carry the signal.
3. **Draft resubmit passes component identity.** When `isComponentLine`, `submitDraftForApproval` sends `__component_product_id = line.product_id`, `__component_dispatch_detail_id` and the quantity per bundle. Dedupe keys on `carrier:product` and therefore stays stable.
4. **Quantity guard filters by product.** The cash-return and returnable consumption queries add `where product_id = ?`. The product is the parent's own product for parent lines and the component product for component lines. Callers pass the effective product.
   - Sibling rows hold a single product, so their results do not change.
5. **Component lines are valued at zero.** `unit_price`, `line_total` and `expected_cash_amount` are 0. `line_meta.allocated_price` keeps the SaleBundleItem price. The parent line's valuation for `whole_bundle` already uses the full bundle price.
6. **Completeness check stays as is.** It already uses the resolver. Grouping by `__component_product_id` works once synthesis tags lines correctly. The comment at L1660 is removed.

## Risks / Trade-offs

- [Unread code paths classify components by the row id] → Tasks grep for `bundle_parent_sale_detail_id` comparisons across `Modules/Pos` and `app/Support/PosReturn` and route them through the helper.
- [The lifecycle and plan persistence services were not traced] → A focused test asserts restock per component on approval (spec "Components restocked on approval"). The task fails loudly if those services key on `sale_detail_id`.
- [Existing tests built on sibling-row fixtures change behaviour] → Re-run the focused bundle return test set. Any expectation change must be justified against the spec, never just updated.
- [Historical in-flight drafts of the old shape] → None exist, because saving was blocked. Already-approved sibling-shape returns are unaffected by the guard change.

## Migration Plan

Code-only deploy; no migration. Rollback is reverting the commit. After deploy, retry TPI-TXN-2026-11-0170 with "Paket Lengkap".
