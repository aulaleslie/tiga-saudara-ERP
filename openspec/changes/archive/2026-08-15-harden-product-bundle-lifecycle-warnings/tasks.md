## 1. Shared Lifecycle Evaluation

- [x] 1.1 Add a reusable bundle lifecycle result/reason contract covering setting mismatch, disabled state, inclusive application-timezone date boundaries, missing definition, invalid or empty live composition, and inactive, missing, or removed components.
- [x] 1.2 Implement selection-mode evaluation that authoritatively verifies bundle id, parent product, transaction setting, current lifecycle dates, and live first-level component eligibility.
- [x] 1.3 Implement captured-snapshot evaluation that compares persisted POS or Sales bundle/component identities with live setting-scoped definitions and returns consolidated warnings without rewriting captured data.
- [x] 1.4 Update or bypass ProductBundleResolver caching where necessary so mutation-boundary lifecycle assertions cannot reuse stale eligibility after an administrative change in the same request/process.

## 2. Eligible New Bundle Selection

- [x] 2.1 Apply eligible-only bundle-parent metadata to POS product search and barcode scan resolution while preserving ordinary-product selection.
- [x] 2.2 Apply eligible-only bundle results to the POS bundle-options endpoint and enforce the same lifecycle assertion in POS cart addition for direct/manual bundle ids.
- [x] 2.3 Replace normal Sales parent-only bundle discovery and unscoped bundle confirmation with parent-and-setting-scoped eligible resolution for create and edit carts.
- [x] 2.4 Return clear unavailable-bundle feedback for new POS and Sales selection attempts that fail authoritative lifecycle evaluation.

## 3. POS Captured-Draft Warnings

- [x] 3.1 Extend POS draft load handling to evaluate captured bundle snapshots before cart mutation and return one structured warning payload when acknowledgement is absent.
- [x] 3.2 Add the POS draft-load acknowledgement prompt and retry the same load action with a request-scoped acknowledgement value while continuing to hydrate bundle metadata from the persisted snapshot.
- [x] 3.3 Add captured-bundle warning evaluation to checkout preflight and non-replay finalization before new payment/checkout posting mutation.
- [x] 3.4 Add POS checkout prompt/retry handling that carries acknowledgement into the immediate server request without storing it in transaction, audit, or durable session data.
- [x] 3.5 Preserve already-posted idempotent replay behavior and existing staged-payment recovery when a user cancels a lifecycle warning.

## 4. Sales and Dispatch Captured-Snapshot Warnings

- [x] 4.1 Evaluate persisted Sales bundle rows on draft edit/load and present a consolidated request-scoped acknowledgement prompt without rehydrating from live composition.
- [x] 4.2 Require the same acknowledgement contract when Sales create/update submission encounters a bundle or component that became ineligible after capture.
- [x] 4.3 Apply captured-snapshot warning and acknowledgement handling to Sales approval without changing persisted bundle rows or bypassing existing authorization and status guards.
- [x] 4.4 Apply captured-snapshot warning and acknowledgement handling to dispatch creation and dispatch approval while keeping persisted Sales component demand authoritative.
- [x] 4.5 Ensure declined warnings leave Sales, dispatch, cart, and status state unchanged for the attempted operation.

## 5. Preserve Hard Gates and Historical Isolation

- [x] 5.1 Confirm acknowledged POS and Sales operations continue through existing component stock, serial, ownership, location, tax, snapshot-integrity, payment, and dispatch reconciliation validation without lifecycle-based bypasses.
- [x] 5.2 Ensure missing live bundle/component records use persisted labels and identity for warnings but remain hard failures when persisted data is insufficient for operational resolution.
- [x] 5.3 Keep completed POS receipts/reprints, Sales history, returns, and reports on persisted data paths without invoking live lifecycle prompts or eligibility filters.

## 6. Focused Regression Coverage

- [x] 6.1 Add focused Product/shared-evaluator tests for enabled, disabled, future, expired, inclusive boundary, open-ended, empty-composition, inactive/missing-component, and independent per-setting cases.
- [x] 6.2 Add focused POS search, scan, bundle endpoint, and cart-add tests proving new ineligible and cross-setting bundle selections are unavailable or rejected.
- [x] 6.3 Add focused POS draft tests for consolidated warning, cancel-without-mutation, acknowledged snapshot hydration, deleted/inactive components, repeated later warning, and persisted-snapshot integrity failure.
- [x] 6.4 Add focused POS checkout tests for preflight/finalize warning acknowledgement, component stock remaining blocking, staged-payment recovery, and posted idempotent replay after live deletion.
- [x] 6.5 Add focused Livewire/feature tests for Sales selection scoping, draft load/submission acknowledgement, inactive or missing components, approval acknowledgement, and cancel-without-status-change.
- [x] 6.6 Add focused dispatch tests proving acknowledgement continues from persisted component demand while insufficient stock, serial, ownership/location, or reconciliation failures still block.
- [x] 6.7 Add focused receipt/reprint, return, and directly affected report regression tests proving completed historical output remains unchanged after live lifecycle edits or deletion.
