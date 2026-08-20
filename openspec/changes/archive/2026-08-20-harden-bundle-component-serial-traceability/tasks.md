## 1. Cart Assignment Integrity

- [x] 1.1 Add focused POS cart regression cases for one serialized SKU used standalone and as a bundle component, duplicate reuse across parent/component positions, multiple serialized components, and a serialized parent plus serialized component.
- [x] 1.2 Refactor the POS browser duplicate helper to include every `bundle_item_serials` assignment and show the affected line/component before submitting an invalid assignment.
- [x] 1.3 Align server-side assign, append, remove, quantity-change, and line-removal paths around cart-wide normalized serial uniqueness and component-specific required counts.
- [x] 1.4 Reject non-whole required quantities for currently serial-required stock-managed components instead of rounding serialized demand.
- [x] 1.5 Run the focused `POSBundleCartManagementTest` and any directly touched cart unit test files and resolve regressions.

## 2. Checkout, Dispatch, and Movement Reconciliation

- [x] 2.1 Add focused checkout cases where a component serial changes status, location, reservation, or dispatch linkage after assignment and assert that finalization leaves no partial checkout, Sale, dispatch, stock, serial-state, or history effects.
- [x] 2.2 Revalidate and deterministically lock bundle-component serial records from current product/source state inside the existing checkout posting transaction before inventory effects.
- [x] 2.3 Trace the existing supported serial history writer for normal Sales and POS automatic dispatch, then route bundle-component posting through that writer so current serial state, component DispatchDetail, stock movement, and history share one lineage.
- [x] 2.4 Add focused split-owner assertions proving one component serial creates exactly one dispatch link and movement/history entry under its actual source owner and location.
- [x] 2.5 Add focused rollback and matching-idempotency-replay assertions proving component serial state, stock, dispatch, and movement/history are neither leaked nor duplicated.
- [x] 2.6 Run `POSSplitSerialBundleCheckoutTest`, `SalesDispatchBundleComponentSerialRegressionTest`, and directly touched serial-dispatch regression files and resolve failures.

## 3. Receipt and Transaction Detail Traceability

- [x] 3.1 Extend the completed receipt projection to expose persisted component-to-serial associations without consulting the live bundle definition or current serial state.
- [x] 3.2 Render component serials beneath their owning bundle components on original receipt and reprint while keeping parent serials separate.
- [x] 3.3 Extend transaction-detail loading/projection and rendering to associate component serials with bundle composition, including standalone and bundled occurrences of the same SKU.
- [x] 3.4 Add focused receipt and transaction-detail tests for serialized parent/component combinations, split-owner components, and display after the live bundle definition changes.
- [x] 3.5 Run the focused receipt and transaction-detail test files touched by these projections and resolve regressions.

## 4. Return Lineage and Exclusivity

- [x] 4.1 Add an end-to-end focused fixture that posts a serialized bundle component through POS and then builds, submits, approves, and receives its POS Return through the linked owner-specific Sales Return.
- [x] 4.2 Resolve return eligibility and execution from persisted POS line, SaleDetail, component DispatchDetail, and serial lineage while remaining independent of the current live bundle definition.
- [x] 4.3 Enforce under the existing return transaction/lock boundary that only one return in a consuming lifecycle state can claim an originally fulfilled component serial.
- [x] 4.4 Assert focused return movement/history reconciliation, current serial state/location restoration, replacement lineage, atomic rollback, and release after a non-consuming return state.
- [x] 4.5 Run `POSReturnSerialSplitOwnerTest`, `POSReturnBundleRegressionTest`, and directly touched POS Return lifecycle/planner test files and resolve regressions.

## 5. Focused Verification and Documentation

- [x] 5.1 Verify that existing persisted checkout, Sale bundle, dispatch, serial, and history records are sufficient for new postings; if historical rows lack authoritative component lineage, preserve the existing fallback display and document the limitation without inferred backfill.
- [x] 5.2 Run only the focused regression files named in sections 1–4 plus any test file whose implementation was directly modified; do not add or run a full-suite verification task for this change.
- [x] 5.3 Record the supported matrix for standalone/parent/component serial combinations, split-owner posting, movement history, historical display, and returns in the change verification notes or test documentation.
