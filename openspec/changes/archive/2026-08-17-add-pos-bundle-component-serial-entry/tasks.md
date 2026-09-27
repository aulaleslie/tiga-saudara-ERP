## 1. Backend: Request and Assignment Endpoint

- [x] 1.1 Extend `StorePosCartSerialAssignmentRequest` with an optional component identifier field (e.g. `bundle_item_id`), validated against the bundle line's actual components when present.
- [x] 1.2 Extend the serial assignment/removal controller action(s) to route to a specific bundle component's serial slot when the component identifier is present, defaulting to current parent-line behavior when absent.
- [x] 1.3 Extend the bundle cart-line JSON structure (in-memory cart representation) to hold a per-component assigned-serial map keyed by component identifier.
- [x] 1.4 Add/extend focused feature tests for component-scoped serial append/remove via the extended endpoint (touched-file scope only).

## 2. Backend: Draft Persistence

- [x] 2.1 Ensure draft save serializes the per-component assigned-serial map as part of the existing bundle cart-line payload (no new table/migration).
- [x] 2.2 Ensure draft load/reopen restores the per-component assigned-serial map and reflects partial completion state.
- [x] 2.3 Add/extend a focused test covering save-draft-with-partial-component-serials then reopen-and-verify-state.

## 3. Backend: Checkout Validation Gating

- [x] 3.1 Extend the preflight/finalize serial fulfillment check (`pos-checkout-serial-stock-validation`) to iterate bundle line components and mark a line unfulfilled when any serial-required component's assigned count is below its required quantity.
- [x] 3.2 Extend unfulfilled-line diagnostics to identify the specific unfulfilled component (product identifier + shortfall) alongside the bundle line index.
- [x] 3.3 Add/extend focused tests: bundle line blocked with incomplete component serials; bundle line passes when parent and all components fully serialized; diagnostics correctly identify the unfulfilled component.

## 4. Backend: Split Posting Propagation

- [x] 4.1 Extend `PosCheckoutSplitPlannerService` `assigned_serials` handling so per-component serial assignments are carried into each grouped child allocation during partitioning.
- [x] 4.2 Ensure a serial-required component split across multiple owner/source groups receives only the serial subset and quantity belonging to each group (no duplication, no omission).
- [x] 4.3 Add/extend focused split-posting tests: single-source serial-required component allocation survives planning; multi-source serial-required component splits correctly across groups.

## 5. Frontend: Bundle-Detail Modal

- [x] 5.1 Add a bundle-detail modal, opened by clicking a bundle cart line, listing all components with serial-required components showing required qty and current assigned count.
- [x] 5.2 Wire the existing continuous-scan input component into the modal, targeting the currently active component.
- [x] 5.3 Implement auto-advance: on reaching a component's required serial count, move the active scan target to the next incomplete serial-required component.
- [x] 5.4 Implement per-component serial removal (chip remove) calling the extended removal endpoint.
- [x] 5.5 Reflect checkout-blocked state on the bundle cart line while any serial-required component (or parent, if serial-required) is incomplete.

## 6. Normal-Sales Regression Test (No App Code Change Expected)

- [x] 6.1 Add a `Modules/Sale/Tests` regression test that dispatches a Sale with a bundle line whose component has `serial_number_required = true`, driving it through the existing serial branch (`selectedSerialNumbers`) of `SaleController::storeDispatch()`.
- [x] 6.2 Assert the resulting `DispatchDetail` for that component carries the correct `serial_numbers`, `product_id`, and `bundle_id`.
- [x] 6.3 Run only this new test file (touched-file scope) to confirm the existing mechanism already passes without code changes.

## 7. Verification

- [x] 7.1 Run `php artisan test --filter=` scoped to each touched test file/class from sections 1-6 (no full suite run).
- [x] 7.2 Manually exercise the bundle-detail modal in a running POS session: scan-complete a serial-required component, verify auto-advance, save as draft mid-entry, reopen draft, complete remaining serials, and confirm checkout succeeds only once fully serialized.
