# Design

## Context

The add-line flow in `PosCartService` already holds the cart's selected customer tier. Its packed branch is tier-aware, but the ordinary branch reads the base sale price directly. The post-selection repricing path resolves tier prices through a separate private resolver that has no fallback to the product's own price. See proposal.md for motivation.

## Goals / Non-Goals

**Goals:**
- Initial ordinary line price matches what repricing would produce for the same customer tier.
- Keep today's fallback to the product's own price when no per-setting price row exists.

**Non-Goals:**
- Changing bundle, packed, or conversion display behavior.
- Changing the product picker, draft hydration, or the repricing resolver.

## Decisions

- **Apply the tier inside the ordinary branch, reusing the price row it already loads.** Pick tier 1 price for WHOLESALER and tier 2 price for RESELLER when greater than zero, otherwise the existing base price expression. Alternative: call the repricing resolver. Rejected because it returns a zero price with no fallback when a price row is missing, which would change behavior for such products; the user chose to keep the fallback.
- **Tag the line `TIER` when a tier price was applied.** Mirrors repricing so later flows treat the line identically. Otherwise it stays `BASE`.
- **No merge-key change.** Non-packed keys already include the unit price, so tier lines merge correctly and match keys rebuilt after a customer change.

## Risks / Trade-offs

- Duplicated tier-mapping logic (add path and repricing resolver) → keep it a few lines and covered by tests; consolidating is a separate cleanup.
