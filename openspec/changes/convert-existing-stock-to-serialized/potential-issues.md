# Potential Issues and Deferred Improvements

This document records concerns intentionally outside the initial conversion delivery. They do not authorize rewriting historical records or broadening implementation scope.

## Historical serial identity

- Units sold before conversion have no recorded serial identity and cannot be retroactively matched safely.
- Historical serial-specific selection, return, or lineage features may remain unavailable for those units.
- A future legacy-return identification workflow would need its own requirements and audit rules.

## Automatic location assignment

- The conversion preserves each location's quantity but assigns individual serials deterministically because the operator scans by owner rather than location.
- A serial's generated location can differ from its physical location until normal inventory handling identifies or corrects it.

## Operational interruption

- Scans are not persisted before final submission. Refresh, navigation, browser failure, or connection loss discards the current page input.
- Persisted drafts can be considered later if actual usage demonstrates a need.

## Scale

- The first version targets at most approximately 100 units per product.
- Larger conversions may require chunked upload, resumable drafts, background validation, or a dedicated batch schema.

## Concurrency and active documents

- Known pending stock-moving documents will block conversion, and final stock drift is checked under locks.
- Newly introduced workflow types must be added to eligibility checks if they can reserve or mutate affected stock without immediately changing `product_stocks`.

## Irreversibility

- The initial feature does not convert a serialized product back to non-serialized form.
- Reversal after serial activity would risk losing identity and lineage and requires a separate controlled design.
