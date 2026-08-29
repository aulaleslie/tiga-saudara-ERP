## 1. Authoritative Dispatch Classification

- [x] 1.1 Add focused POS posting regressions proving stock-managed parent/component DispatchDetails persist `is_inventory_managed = true` and audit-only service/non-stock rows persist `false` in inline and directly affected split bundle paths.
- [x] 1.2 Update POS stock-movement and audit-only DispatchDetail creation to persist explicit inventory classification without changing existing stock, serial, tax, revenue, or payment behavior.
- [x] 1.3 Add a shared sold-source classifier that distinguishes explicit inventory, explicit non-inventory, and narrowly supported historical nullable evidence with actionable blocker reasons.
- [x] 1.4 Use the shared classifier in both preview and locked persisted discovery, retaining the existing Location → Dispatch → DispatchDetail lock order and revalidation.

## 2. Bundle-Aware Immutable Evidence

- [x] 2.1 Add focused discovery tests for ordinary Sale and POS stock-managed bundle parents/components, including preview/persistence parity and one immutable source per physical DispatchDetail.
- [x] 2.2 Remove `bundle_id` as an automatic blocker while keeping missing-product, invalid-location, explicit non-inventory/service, and contradictory historical evidence blocked.
- [x] 2.3 Persist an explicit new sold-source snapshot/hash version with bundle identity, inventory classification, and compatibility-classification evidence for newly discovered rows.
- [x] 2.4 Update canonical live-hash revalidation to use the exact historical payload for unversioned/version-1 sources, the bundle-aware payload for version 2, and an actionable blocker for unknown versions.
- [x] 2.5 Add regression tests proving live bundle/classification mutation blocks version-2 lifecycle operations while historical unversioned hashes continue to submit and approve without false mismatch.

## 3. Allocation, Returns, and Lineage Safety

- [x] 3.1 Add focused regressions proving bundle sold-source quantities equal their authoritative DispatchDetails and are not synthesized or duplicated from `sale_bundle_items`.
- [x] 3.2 Add serialized bundle regressions proving product, location, serial, supplier, and receiving-detail provenance remain exact through discovery and allocation.
- [x] 3.3 Add bundle return regressions proving executed returns reduce eligibility once, pending returns do not reduce it, and existing ambiguity blockers remain intact.
- [x] 3.4 Verify reconciliation presents supported stock-managed bundle movements as eligible/allocated while retaining blockers for service, non-stock, missing, or ambiguous evidence.

## 4. Focused Verification

- [x] 4.1 Run the focused Consignment sold-source/allocation tests and directly affected POS and standard Sales bundle posting tests; do not run or plan the full application suite.
- [x] 4.2 Run PHP syntax checks for touched PHP files and compile/check directly changed Blade views if any.
- [x] 4.3 Run `git diff --check` and `openspec validate support-consignment-bundle-sold-source-discovery --strict`.
