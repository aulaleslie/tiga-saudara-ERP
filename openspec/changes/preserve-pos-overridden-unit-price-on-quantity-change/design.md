# Design

## Context

See `proposal.md` for the user-facing problem. POS stores base-unit quantity, price source, displayed unit price, and canonical minor-unit row totals in the cart. `PosCartService::updateLineWithinLock` already refreshes `LINE_UNIT_PRICE_OVERRIDE` metadata but sends `LINE_TOTAL_OVERRIDE` through `restoreStandardPricing`, which can reapply tier or packed conversion pricing. `PosRowOverrideArithmetic::applyRowTotal` deliberately keeps a non-divisible entered total exact and derives a two-decimal unit price for display.

## Goals / Non-Goals

**Goals:**

- Preserve exact row-total authority at the quantity where the total was entered, then use the already displayed two-decimal unit price as authority on a later quantity change.
- Keep the existing quantity authorization, stock, approval invalidation, customer repricing, and draft/checkout monetary paths.
- Keep canonical monetary metadata aligned with the new quantity and price source.

**Non-Goals:**

- Change discount or tax editing rules, introduce new POS controls, or alter completed transactions.
- Change automatic packed pricing for rows without an override.
- Add a schema change or plan a full application test suite.

## Decisions

### Convert row-total authority to unit-price authority when quantity changes

The quantity mutation will detect `LINE_TOTAL_OVERRIDE` before clearing its old canonical fields. It will capture the stored two-decimal `unit_price` in minor units, update quantity, and use the existing unit-price arithmetic to compute new gross, discount, net, and tax metadata. The row's active price source becomes `LINE_UNIT_PRICE_OVERRIDE`; the old exact total remains only in the existing override audit history. This makes the source describe what is authoritative after the quantity change and prevents downstream consumers from treating the original total as fixed.

Alternative: retain `LINE_TOTAL_OVERRIDE` with a changed total. That leaves ambiguous whether the original approved total or the derived unit price is authoritative and risks downstream code reading stale total metadata.

### Keep automatic repricing behind price-source precedence

The existing packed repricing branch runs only when the resulting source is `PACKED`. After conversion to `LINE_UNIT_PRICE_OVERRIDE`, it must not repack from `pricing_basis`. The same applies to an explicit unit-price override. Customer selection remains a separate reset path: it clears the override and resolves standard tier/packed pricing for the newly selected customer.

Alternative: remove `pricing_basis` from overridden lines. Retaining it supports later customer repricing and draft compatibility without another price lookup.

### Recalculate in integer minor units

Use the same two-decimal unit price visible to the cashier. For Rp10.000,00 at quantity three, `unit_price` is Rp3.333,33; at quantity four, gross becomes Rp13.333,32. Preserve the entered Rp10.000,00 exactly until quantity changes. Recompute canonical metadata through the existing arithmetic service, then let the normal snapshot and checkout path consume it.

Alternative: carry a hidden unrounded ratio into later quantities. That would produce a total inconsistent with the unit price shown to the cashier and with the clarified business rule.

## Risks / Trade-offs

- [A non-divisible total yields a slightly different per-unit ratio after quantity changes] → Use the displayed two-decimal price intentionally and cover the Rp10.000,00 ÷ 3 example.
- [Stale canonical fields could preserve the old total] → Clear old row-total fields and assert refreshed source, net, gross, and tax in a focused feature test.
- [Packed rows could silently repack after an override] → Cover explicit unit-price and row-total overrides on conversion-capable tier rows, plus automatic packed pricing without an override.
- [Other quantity paths, such as serial-driven increments, may bypass `updateLineWithinLock`] → Apply the same transition through the shared override refresh path and cover the relevant path if it is exercised by a focused test.

## Migration Plan

No database migration. Deploy the cart-service change and focused tests together. Existing mutable carts use the new rule on their next quantity change; saved drafts retain their stored source and price until edited. Rollback restores prior quantity-change behavior without data conversion.
