## Context

Phase 2 discovery currently selects approved `dispatch_details` from active consignment locations, then marks a row unsupported when its product is missing, `is_inventory_managed` is false-like, or `bundle_id` is present. This conflicts with both Sales posting paths: ordinary Sales creates stock-managed dispatch obligations for bundle components with `is_inventory_managed = true`, while POS creates a DispatchDetail for every actual stock allocation but currently omits the nullable classification field. POS also creates audit-only DispatchDetails for non-stock content and omits the same field.

The dispatch detail is already the authoritative physical unit used for stock deduction, serial `SOLD` linkage, return identity, and sold-source uniqueness. `SaleBundleItem` rows describe bundle composition and commercial allocation; discovery must not independently turn them into sold sources.

## Goals / Non-Goals

**Goals:**

- Admit approved stock-managed bundle dispatch movements from active consignment locations.
- Keep non-stock/service and ambiguous evidence visible as blockers.
- Make future POS dispatch classification explicit and make historical nullable rows classifiable without rewriting them.
- Preserve bundle classification in new immutable source evidence and detect later mutation.
- Retain compatibility with every historical unversioned sold-source hash.
- Keep ordinary Sales and POS behavior aligned and idempotent.

**Non-Goals:**

- Changing bundle pricing, revenue allocation, inventory deduction, dispatch approval, or return execution.
- Creating sold sources from `sale_bundle_items` or catalog bundle definitions.
- Rewriting existing sold sources, confirmations, allocations, or Purchases.
- Automatically repairing malformed historical dispatches whose physical classification cannot be established.

## Decisions

### 1. DispatchDetail remains the only sold-source authority

Discovery will continue to create at most one `ConsignmentSoldSource` for each approved DispatchDetail. A non-null `bundle_id` is provenance, not evidence that the movement is unsupported. Bundle parents and components are not deduplicated by Sale or bundle because each stock-managed DispatchDetail represents an independently posted physical quantity; the existing unique dispatch-detail identity prevents rediscovery.

Deriving sources from `SaleBundleItem` was rejected because those rows can be non-stock, can carry informational quantities, and do not themselves prove a stock movement.

### 2. Classify inventory evidence explicitly, with a narrow legacy fallback

New POS stock movements will persist `is_inventory_managed = true`; new POS audit-only movements will persist `false`. Ordinary Sales already writes these values.

Discovery will classify:

- explicit `true` as inventory-managed when product and location evidence are valid;
- explicit `false` as unsupported;
- historical `null` through a compatibility path requiring an existing stock-managed product and a valid source location.

The compatibility decision and original nullable value will be retained in reconstruction/source evidence. A missing product, non-stock current product, absent location, or contradictory evidence remains blocked rather than guessed. A data backfill was rejected because it would rewrite historical evidence and cannot reliably distinguish every legacy audit-only row after master-data changes.

### 3. Preview and persistence share one classifier

The service will use a single classification method for preview and locked persistence so the *classification* of a given piece of evidence — eligible, or blocked with a specific reason — cannot diverge between the two paths. Persistence will run the classifier only after locking and revalidating Location, Dispatch, DispatchDetail, and Product authority in the existing deterministic order.

Classification parity is not the same as reporting parity. Where locked revalidation finds the Location, Dispatch, or DispatchDetail authority no longer qualifies at all — a reclassified or deactivated consignment location being the common case — persistence excludes the row without writing a sold source, while preview surfaces it as a blocker so the condition stays visible. Both paths classify the evidence identically (for example `INVALID_LOCATION`); they differ only in outcome, because persisting a sold source for a location that no longer qualifies would create invalid immutable evidence. Preview therefore reports it under `blocked`, and persistence under `excluded`.

### 4. Version new canonical source hashes without changing historical validation

Newly discovered sources will carry an explicit hash/snapshot version. The new canonical payload will add at least `bundle_id` and the persisted `is_inventory_managed` value alongside the existing dispatch, location, product, quantity, serial, tax, POS, and status fields.

Live revalidation will select its payload shape from the stored snapshot version:

- missing/version 1 uses the exact historical payload;
- version 2 uses the bundle-aware payload;
- unknown versions block lifecycle operations.

Inferring version from the presence of a JSON key was rejected because mutable or partially repaired JSON could silently change the validation contract.

### 5. Serial provenance is resolved under lock and is all-or-nothing

Serial identities are normalized once, then resolved in a single query and locked in
deterministic ID order immediately after the Product lock, preserving the
Product → ProductSerialNumber hierarchy used by Consignment receiving and the billing
lifecycle. Each captured serial must resolve to exactly one live record owned by the
detail's product.

Resolution uses the composite `(product_id, serial_number)` identity the schema enforces
(`unique(['product_id', 'serial_number'])`), so the query is scoped to the locked product.
Matching on serial text alone was rejected: identical text under a different product is
legitimate, unrelated authority, and reading it would both block valid dispatches and take
locks on rows this transaction has no business holding. A serial with no row for the
dispatch's product is simply unresolvable here, whether or not the text exists elsewhere.

Missing, duplicated, or multiply-resolving serials produce a reconstruction
blocker naming the offending evidence, and no `ConsignmentSoldSourceSerial` rows are
written for that source. Linking whatever happened to resolve was rejected: partial
relational provenance is indistinguishable from complete provenance downstream, so a
sold source would appear fully serialized while silently missing lineage.

Preview classifies the same evidence with the same resolver but takes no locks, since it
persists nothing.

### 6. Returns remain dispatch-detail scoped

Existing Sale Return and POS cash-return reconstruction will continue to operate by DispatchDetail identity. Bundle association will be snapshotted but will not change the return quantity algorithm. Tests will cover a bundle movement with effective and pending returns to prove eligibility is neither inflated nor silently discarded.

### 7. Focused verification only

Verification will cover the Consignment allocation tests and directly affected POS/Sale bundle-posting tests. The full application suite is outside this correction.

## Risks / Trade-offs

- [A historical nullable row may be classified from master data that changed after posting] → Restrict fallback to otherwise-valid stock-managed product/location evidence, preserve the compatibility decision, and block contradictions instead of backfilling.
- [Bundle parent and component quantities may look duplicated commercially] → Use only physical DispatchDetails; do not synthesize sources from bundle rows, and assert one source per detail plus quantity equality to each physical movement.
- [Adding fields to the canonical payload could invalidate existing approvals] → Use explicit version dispatch and retain the exact version-1 hash algorithm.
- [POS producer and discovery may drift again] → Add posting tests that assert explicit classification on both inventory and audit-only details, plus preview/persistence parity tests.
- [Unknown future hash versions could be interpreted incorrectly] → Fail closed with an actionable unsupported-version blocker.
- [Serial authority can change between candidate selection and persistence, or resolve incompletely] → Resolve serials in one query under lock after the Product lock, require an exact one-to-one match to the detail's product, and block with named evidence instead of writing partial provenance.

## Migration Plan

1. Deploy source classification and hash-version-aware readers before relying on new versioned snapshots.
2. Deploy explicit POS dispatch classification and bundle-aware discovery together.
3. Run focused discovery against fixture data covering ordinary Sale and POS bundle movements.
4. Do not update existing sold-source rows or backfill nullable historical dispatch classifications.
5. Rollback may restore the earlier blocker behavior only while no version-2 source has entered an active confirmation; version-aware readers should otherwise remain available to preserve created evidence.

## Open Questions

None. Ambiguous historical nullable evidence is intentionally blocked rather than administratively guessed.
