## Why

Normal Sales bundle persistence already works across cart selection, draft editing, persistence, approval, and dispatch, but several row-identity, quantity, and rollback guarantees are implicit or only covered indirectly. Sequence 5 hardening should make those guarantees executable without redesigning the working flow or expanding into POS owner-split and dispatch-identity concerns owned by later sequences.

## What Changes

- Add focused regression coverage for distinct bundled and non-bundled Sales rows, including shared parent and component products.
- Verify component quantities remain exactly `parent quantity × quantity per bundle` through repeated quantity changes and draft updates.
- Verify removing one bundled cart row does not mutate another row's captured composition.
- Verify Sale, parent detail, and component persistence rolls back atomically when component persistence fails.
- Verify every linked component row carries the parent Sale identity and remains attached to the correct parent detail.
- Confirm through tests that normal Sales creation and editable-draft updates defer component stock enforcement to dispatch.
- Keep production changes conditional and narrow: change application code only if a regression test demonstrates violation of one of these invariants.
- Record standalone POS/legacy component rows with `sale_detail_id = null` as an explicit non-goal for this change; their downstream dispatch compatibility belongs to POS/dispatch hardening.

## Capabilities

### New Capabilities

- `normal-sales-bundle-persistence`: Defines row identity, component quantity, parent linkage, atomic persistence, captured-draft, and deferred-stock guarantees for normal Sales bundles.

### Modified Capabilities

None.

## Impact

- Primarily affects focused tests around `ProductCart`, `EditForm`, `SaleNormalizer`, and `SaleService`.
- Production changes, if proven necessary, are limited to the normal Sales cart or persistence path.
- No schema change, public API change, POS split-planner change, dispatch redesign, or historical data rewrite is planned.
- Existing `sales-standalone-bundle-rows` and POS bundle capabilities remain unchanged.
