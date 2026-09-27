## 1. Canonical Bundle Snapshot Contract

- [x] 1.1 Add focused unit fixtures that define the canonical parent and first-level component snapshot fields, legacy aliases, numeric normalization, null handling, and deterministic component ordering.
- [x] 1.2 Implement a reusable canonical bundle snapshot mapper for POS integrity hashing and Product lifecycle comparison without reloading live prices into captured data.
- [x] 1.3 Normalize POS component quantity-per-bundle semantics while preserving backward compatibility with existing `bundle_items.quantity` payloads and persisted drafts.
- [x] 1.4 Update new POS bundle cart lines to capture every canonical component field required for integrity, drift diagnostics, and later operational validation.

## 2. POS Persisted-Draft Integrity

- [x] 2.1 Add a versioned complete POS snapshot hash projection covering authoritative header, parent-line, bundle, component, serial, and totals data.
- [x] 2.2 Retain a legacy hash verifier and atomically upgrade valid legacy draft hashes under the existing transaction lock before cart hydration.
- [x] 2.3 Reject invalid legacy hashes and current-hash mismatches with `SNAPSHOT_DRIFT` without hydrating or mutating the draft.
- [x] 2.4 Add regression tests that independently mutate bundle parent metadata, component product identity, quantity, informational allocation, stock classification, and serial classification.
- [x] 2.5 Add compatibility tests proving valid legacy drafts upgrade without changing captured cart data and deterministic component reordering does not create false drift.

## 3. Definition Drift Evaluation

- [x] 3.1 Extend the bundle lifecycle evaluator to compare normalized captured and live component quantities consistently for both Sales and POS.
- [x] 3.2 Add a stable informational-allocation drift reason containing captured and current values while ignoring standalone product-price changes that have not refreshed the bundle definition.
- [x] 3.3 Add stable operational-classification drift diagnostics for parent and component stock-managed and serial-required changes.
- [x] 3.4 Verify acknowledged continuation retains captured parent price, component identities, quantities, and informational allocations for component add, remove, quantity, price-allocation, and bundle deletion cases.
- [x] 3.5 Add Product, Sales, and POS tests for consolidated warnings and request-scoped acknowledgement across normalized drift reasons.

## 4. Operational Safety Gates

- [x] 4.1 Centralize current product classification resolution for POS stock resolution, serial validation, owner-aware route selection, split planning, and posting preparation.
- [x] 4.2 Replace path-dependent captured/live `stock_managed` decisions with the current classification while retaining captured flags for integrity and diagnostics.
- [x] 4.3 Re-evaluate current parent serial requirements on draft load and checkout gates using the existing parent-line serial assignment workflow.
- [x] 4.4 Add a dedicated blocking validation for current serial-required bundle components, including bundle-line and component details, without adding component serial-entry UI or APIs.
- [x] 4.5 Add tests proving lifecycle acknowledgement cannot bypass current stock, serial, ownership, location, or unsupported component-serial failures and that failed validation leaves checkout/payment/Sale/dispatch/stock state unchanged.

## 5. Sales Path Consistency

- [x] 5.1 Inventory reachable Sales create and mutable-update entry points and add regression tests demonstrating whether each path evaluates captured bundle drift before persistence.
- [x] 5.2 Route any reachable controller or Livewire bypass through the shared bundle snapshot evaluator with request-scoped acknowledgement.
- [x] 5.3 Verify Sales edit hydration continues to derive component identity, quantity, and price from `sale_details` and `sale_bundle_items` after live definition mutation or deletion.
- [x] 5.4 Verify acknowledged Sales updates preserve captured bundle rows and do not refresh component allocations from `ProductBundleItem` or `ProductPrice`.

## 6. POS Preflight, Finalize, and Replay

- [x] 6.1 Verify checkout preflight evaluates current bundle drift without persisting acknowledgement or mutating checkout/payment state.
- [x] 6.2 Ensure each new finalize request re-evaluates drift and operational gates before checkout ledger creation, including changes made after a successful preflight or payment-stage entry.
- [x] 6.3 Add race regression tests for quantity, informational allocation, lifecycle, stock, and serial-classification changes between preflight and finalize.
- [x] 6.4 Add bundle-specific idempotency tests proving a matching posted checkout replays from its stored snapshot after live mutation or deletion without duplicate Sales, payments, dispatches, or stock movement.
- [x] 6.5 Verify reuse of an idempotency key with a different canonical cart or payment payload remains a hard mismatch.

## 7. Verification and Documentation

- [x] 7.1 Run focused Product lifecycle, Sales bundle, POS draft roundtrip, checkout preflight/finalize, split-posting, and idempotency test suites.
- [x] 7.2 Verify backward compatibility for pre-existing drafts and Sales documents containing legacy bundle data structures.
- [x] 7.3 Update and sync OpenSpec change documentation to reflect the final canonical snapshot contract and operational classification precedence rules.
