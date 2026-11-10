# Design

## Context

See proposal.md for motivation. Four query services use `InventoryReplaySupport`: inventory summary, detail, valuation, and warehouse valuation. Warehouse quantity duplicates the metadata loader and date resolver. Both loaders select a nonexistent column. The Transfer model's fillable entry does not establish a persisted field. Existing report tests inspected do not exercise persisted TRF metadata loading.

## Goals / Non-Goals

**Goals:** Correct both SQL paths and use the agreed direction-specific dates consistently in replay, balances, and exports.

**Non-Goals:** Introduce a transfer date field, change transfer execution or numbering, refactor the report architecture, or redesign other Produk reports.

## Decisions

1. Remove `transfer_date` from both selects, metadata maps, and date fallback expressions. Use the existing persisted dispatch/receipt fields. A migration would introduce an undefined business date solely to accommodate an invalid query.
2. Preserve current delta-based direction classification. Outgoing uses dispatch time; incoming uses receipt time. Missing corresponding metadata falls through to the existing transaction `created_at` fallback. Do not use the opposite leg: a receipt can occur in a different reporting period from dispatch.
3. Correct both existing implementations directly. Extracting a new abstraction is unnecessary for this bounded fix; focused tests must cover both paths to prevent divergence.
4. Inspect active reports listed by the Produk tab for use of these loaders or equivalent invalid field references. Record additional failures separately if they have a different cause. The report of all tab entries failing is context to investigate, not proof they share this code path.
5. Use focused report tests only, as requested. Persist actual transfers against the migrated schema so SQL column errors are observable. Cover all five consumers, a transfer spanning period boundaries, missing corresponding timestamps despite an opposite timestamp being present, and absent transfer metadata. Reuse existing report/export test patterns rather than starting a broad test-suite run.

## Risks / Trade-offs

- Missing timestamps can move historical rows into a different reporting period compared with the previous opposite-leg fallback → Assert explicit period/as-of expectations and document this intended correction.
- Shared fixtures without transfers can miss invalid selects → Add persisted TRF fixtures exercising both loaders.
- SQLite and MySQL can differ → Use the real migrated schema in focused tests; run a focused MySQL check if the existing test environment is available, without requiring a broad suite.

## Migration Plan

Deploy the code correction and focused tests together. No database migration or data repair is required. Rollback is a code revert; historical inventory transactions remain unchanged.

## Existing spec maintenance constraint

Strict change validation passes, but reports that the existing warehouse quantity and warehouse valuation main specs contain delta headers instead of a main Requirements section. Archive would refuse updates to those two specs until their existing structure is corrected. This does not block implementation; preserve their requirement content when performing that separate structural maintenance before archive.
