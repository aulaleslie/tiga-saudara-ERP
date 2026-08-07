## Context

The Sales Price & Stock Snapshot importer reads Accurate XLSX rows containing `Name*`, `SellPrice`, and `Stock`. It resolves an existing canonical product and determines the row owner through the shared marker resolver: DAIZU keyword ownership has priority, followed by a leading `*`, a trailing ` TP`, and the Perdana fallback. The resolved owner currently receives the stock adjustment and all three selling tiers, but the processor also fills absent price rows belonging to unrelated settings with the same imported value.

The business rule is that a source row is authoritative only for its resolved owner's stock and selling tiers. A shared catalog product can therefore legitimately have no price row for another company until that company establishes one through its own workflow.

## Goals / Non-Goals

**Goals:**

- Keep owner resolution, including DAIZU precedence, as the single source of truth for both stock and selling-tier mutations.
- Update `sale_price`, `tier_1_price`, and `tier_2_price` only on the resolved owner's `product_prices` row.
- Preserve the existing atomic owner-row price, stock, and adjustment-transaction mutation.

**Non-Goals:**

- Changing Accurate workbook columns, product resolution, marker syntax, or the DAIZU keyword set.
- Creating missing price rows for non-owner settings.
- Modifying unrelated sales-import or purchase-import cross-setting price synchronization rules.
- Retrospectively deleting price rows previously seeded by older imports.

## Decisions

### Remove only the non-owner price seeding step

The snapshot processor SHALL keep its `firstOrNew`/save behavior for the single `(product_id, resolved setting_id)` price record, then proceed directly to the stock snapshot mutation. The helper that creates missing price rows for all settings SHALL no longer be invoked by this workflow.

This is preferable to filtering the global settings list because it makes the mutation boundary explicit: one imported row has exactly one owner price target. It also avoids treating settings without locations differently from other non-owner settings.

### Keep all three selling tiers aligned for the owner

An Accurate `SellPrice` remains the value for Sale Price, Tier 1, and Tier 2 of the resolved owner. This matches the present snapshot-import contract and the confirmed business requirement. Changing only the base sale price was considered, but would leave owner tiers stale and diverge from the source snapshot's current behavior.

### Preserve DAIZU-first owner resolution

The shared resolver continues to resolve KEDELE, KEDELAI, and RAGI product names to DAIZU before evaluating `*`, ` TP`, or the default route. The change limits price propagation; it does not alter ownership semantics.

## Risks / Trade-offs

- [A non-owner business lacks a price row after import] → This is intentional; a price row must be established through that business's own authorized workflow rather than copied from another owner.
- [Existing tests encode cross-setting seeding] → Replace them with assertions that the resolved owner changes and other existing or absent owner rows remain untouched.
- [Previously seeded records remain] → No data migration is performed; historic rows remain as-is to avoid destructive changes.

## Migration Plan

Deploy as an application change with no schema migration. New imports immediately stop cross-company seeding. If rollback is required, redeploying the prior application restores the former behavior; price rows created before this change are not automatically removed in either direction.

## Open Questions

None.
