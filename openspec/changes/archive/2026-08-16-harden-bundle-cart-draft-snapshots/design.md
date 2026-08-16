## Context

Bundle selection already copies parent price and first-level component data into Sales cart options and POS session lines. Sales persists that data in `sale_details` and `sale_bundle_items`; POS drafts persist it in `pos_transaction_lines.line_meta`. Runtime lifecycle checks compare captured component identities with the live definition and allow acknowledged continuation from the captured data.

The protection is incomplete. `PosTransactionSnapshotMapper::buildSnapshotHash()` hashes the parent line and `bundle_id` but excludes the component array and most operational metadata. POS also captures component quantity as `quantity`, while the lifecycle evaluator only compares `quantity_per_bundle`. Informational allocation and stock/serial-classification drift have no consistent contract, and some Sales entry points may bypass the Livewire lifecycle check.

This is a cross-module hardening change across Product lifecycle evaluation, Sales cart persistence, and POS draft/finalize handling. It must preserve existing posted history, owner-split posting, idempotency, and the Sequence 3 pricing model.

## Goals / Non-Goals

**Goals:**

- Establish one canonical, first-level bundle snapshot shape shared conceptually by Sales and POS.
- Detect corruption of every authoritative field in a persisted POS bundle snapshot.
- Detect relevant live-definition drift while continuing from captured commercial data after acknowledgement.
- Use current stock-managed and serial-required classifications at operational gates.
- Revalidate before POS finalization and preserve historical idempotent replay.
- Apply equivalent drift checks to all reachable Sales create and mutable-update paths.

**Non-Goals:**

- Adding POS UI, endpoints, or persistence for assigning serials to individual bundle components.
- Recursively expanding nested bundles.
- Repricing existing carts or drafts from current product or bundle prices.
- Adding a Sales snapshot hash or redesigning `sale_bundle_items`.
- Changing checkout payment, owner splitting, tax, inventory, or dispatch algorithms beyond consuming the clarified snapshot contract.
- Rewriting posted Sales, POS checkouts, receipts, returns, or reports.

## Decisions

### 1. Canonicalize captured bundle data at transaction boundaries

Each bundled parent line will expose a normalized snapshot containing the bundle identifier and captured name, parent commercial price, and ordered first-level components. Each component snapshot will contain its definition-row identifier when available, product identifier and captured name, quantity per bundle, informational allocation price, and captured stock/serial flags for audit and drift diagnostics.

POS may retain its existing external payload keys for compatibility, but hashing and comparison will pass through one canonical mapper. Component ordering will be deterministic and will not change the commercial meaning of a cart.

This is preferred over reloading `ProductBundle` during persistence because live reload would silently replace values selected earlier and violate the captured-price contract.

### 2. Version and expand POS snapshot integrity hashing

The current POS hash projection will remain available as the legacy verifier. New and resaved drafts will store a versioned hash over the complete canonical transaction snapshot, including bundle component data and captured operational metadata.

When loading a legacy draft, the service will first verify the stored hash using the legacy projection. If valid, it will atomically upgrade the hash to the new projection while the transaction is locked, before hydrating the cart. A legacy mismatch remains a hard `SNAPSHOT_DRIFT` failure. This avoids invalidating every existing draft at deployment while ensuring subsequent changes are protected.

The hash is an integrity consistency check, not a cryptographic authorization boundary; database write authorization remains unchanged.

### 3. Compare semantic drift without replacing captured data

The evaluator will compare normalized captured data with the saved live bundle definition for:

- bundle existence, setting, lifecycle dates, and active state;
- component additions and removals;
- quantity-per-bundle changes; and
- changes to saved `informational_item_price` caused by an administrator saving the bundle copy.

Warnings will contain stable reason codes and both captured and current values where useful. Acknowledgement permits the operation to continue with captured component identities, quantities, allocations, and parent commercial price. A change to a product's independent live sale price does not constitute bundle drift unless the bundle definition itself has been saved and its informational snapshot changed.

### 4. Treat current operational classifications as safety authority

Captured `stock_managed` and serial-required values remain in the snapshot for audit and drift diagnostics, but current product classifications will govern stock resolution, serial validation, split-planning classification, and posting. Classification changes will be reported with the other drift warnings and cannot cause composition or price refresh.

Tax classification belongs to each stock/allocation owner setting (`is_pkp`), not the POS transaction owner. A non-PKP POS may generate a taxed Sales allocation when the consumed stock belongs to a PKP setting, and a PKP POS consuming stock from a non-PKP setting produces a non-tax Sales allocation. For non-serialized products, allocation iterates configured (location_id, setting_id) sources in exact priority sequence and consumes available stock from each source before moving to the next; PKP status and taxability do not influence source priority. For serialized products, the owner setting is resolved directly from the selected serial number's actual location ownership, and tax classification follows that resolved owner.

If a current bundle component requires serial assignment, POS finalization will fail with a specific unsupported-component-serial validation because the present UI and cart API can assign serials only to parent lines. A lifecycle acknowledgement cannot bypass this operational gate. Component serial assignment is deferred to Sequence 8.

This is preferred over trusting stale captured flags, which could bypass newly required inventory or serial controls.

### 5. Revalidate at every mutable boundary, but not historical replay

POS draft load, checkout preflight, and new finalization will independently evaluate current drift. Preflight acknowledgement is request-scoped and does not suppress finalize checks. Finalize will evaluate early policy and fulfillability gates before creating or mutating checkout/payment ledger state, and transactional posting will repeat authoritative stock and serial validation under database locks.

A matching already-posted idempotency key remains a historical replay: it verifies the original request fingerprint from stored checkout data and returns the stored result before applying current bundle drift rules.

Sales create and every reachable mutable update route will evaluate the same normalized captured snapshot immediately before persistence. Approval and dispatch retain their existing request-scoped lifecycle checks. Posted display, receipt, return, and report paths remain isolated from live definitions.

### 6. Keep Sales persistence authoritative without adding a new hash

Sales continues to use `sale_details` and `sale_bundle_items` as its durable snapshot. Edit hydration must derive component identity, quantity, and price from those rows. This change will add validation and regression coverage around reachable mutation paths rather than introducing a second integrity mechanism or schema change.

## Risks / Trade-offs

- **Legacy POS hashes cannot prove that previously unhashed component metadata was never altered** → Verify the legacy projection before a one-time lazy upgrade, record no false guarantee about pre-upgrade component integrity, and protect the full snapshot thereafter.
- **Canonicalization differences could reject valid drafts** → Normalize numeric precision, nulls, component ordering, and legacy field aliases in one mapper with focused unit fixtures before enabling the new hash version.
- **Current classification changes can make an old draft unfulfillable** → Return explicit operational validation details; preserve the draft and captured commercial data so the user can cancel or resolve it safely.
- **Informational-price warnings may be noisy after administrative saves** → Warn only when the saved bundle-definition allocation differs, not when a standalone `ProductPrice` changes.
- **Duplicate validation in controllers and Livewire components can diverge** → Centralize snapshot normalization/evaluation in services and make all entry points delegate to them.
- **Component serial blocking exposes an existing unsupported configuration** → Use a dedicated error code and defer actual assignment workflow to Sequence 8 rather than adding incomplete UI in this change.

## Migration Plan

1. Add canonical snapshot mapping and dual legacy/current hash verification without changing persisted business rows.
2. Enable lazy hash upgrade for valid legacy POS drafts under the existing transaction lock.
3. Route lifecycle comparison and operational classification checks through the canonical representation.
4. Add Sales/POS regression coverage, including existing-draft compatibility and posted replay.
5. Deploy without rewriting posted transactions. Rollback restores the previous verifier; versioned hashes must remain readable or be treated as recomputable from unchanged draft rows during rollback.

## Open Questions

None. Bundle-component serial-entry UX and assignment semantics remain intentionally deferred to Sequence 8.
