# Proposal

## Why

POS global payment filtering is visibly slow, although no repeatable timing baseline exists yet. The current implementation combines full-candidate settlement calculations, per-transaction replacement-return queries, and repeated calculations between the table and summary cards.

## What Changes

- Establish a repeatable, focused before/after performance baseline for the POS global payment workspace.
- Batch replacement-sale discovery for list and summary projection consumers, retaining single-record resolver compatibility.
- Reuse settlement projections within an operation instead of calculating filtered page rows twice.
- Avoid recalculating summary totals solely because a card selection or a table-only filter changed, while ensuring subsequent requests still observe committed financial changes.
- Preserve existing financial calculations, authorization, filter/search behavior, summary scope, loading feedback, ordering, and pagination.
- Restrict verification to affected resolver, projection, Livewire, and replacement-return scenarios; no full-suite test run is planned.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

None. This is an implementation refactor preserving `global-pos-multi-payment` behavior. The change opts out of spec deltas with `skip_specs: true`.

## Impact

- Primarily `PosReachableSalesResolver`, `PosSettlementProjectionService`, `GlobalPosPaymentTable`, and `PosSummaryCards`; workspace views/events may need small supporting adjustments.
- Focused tests in `Modules/Pos/Tests` and a reproducible benchmark record or command recipe.
- No migrations, indexes, persisted projection tables, infrastructure, dependencies, or payment-write workflow changes.
- Full-candidate PHP filtering remains in scope as existing behavior; SQL aggregation and schema tuning are deferred.
