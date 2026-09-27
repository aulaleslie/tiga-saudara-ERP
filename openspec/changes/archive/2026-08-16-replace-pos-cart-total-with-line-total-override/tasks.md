> **Revision note.** The original scope retired every unit-price override. That decision is superseded: POS must expose both `Ubah Harga Satuan` and `Ubah Total Baris` at row scope. Cart-wide override remains retired. Items below that stay checked were verified as still correct under the revised scope; items unchecked were invalidated by the restored unit-price requirement, the canonical arithmetic authority, the cart mutation lock and execution coordinator, or the fingerprint rework.

## 1. Establish Row-Override Domain Contract

- [x] 1.1 Inventory every active `PRICE_OVERRIDE` and `TOTAL_PRICE_OVERRIDE` route, controller, service, snapshot field, permission, role bundle, UI control, approval-queue branch, report renderer, and checkout consumer; distinguish active mutation paths from historical read compatibility.
- [x] 1.2 Add the distinct `LINE_UNIT_PRICE_OVERRIDE` action and supervisor-audit mapping alongside `LINE_TOTAL_OVERRIDE`, retaining legacy action constants required to read historical records.
- [x] 1.3 Ensure neither legacy `PRICE_OVERRIDE` nor `TOTAL_PRICE_OVERRIDE` can be created for, or authorize, a new operation, while historical rows remain readable.
- [x] 1.4 Rework the canonical line fingerprint to cover the real line contract: line and product ID, quantity, current unit price and price source, conversion/UOM identity and factor, tax inputs, row discount type and value, customer context, canonically ordered serials, bundle identity, and canonical bundle components including component conversion/UOM fields.
- [x] 1.5 Bind the approval payload fingerprint to action type, exact requested monetary value, POS session ID, line ID, and requester ID.
- [x] 1.6 Build customer context through one shared method from authoritative `selected_customer_id`, `selected_customer_tier`, and setting PKP context; remove any use of `customer_group` or client-submitted context.
- [x] 1.7 Add focused unit coverage proving fingerprint stability for equivalent row data and drift detection for customer/tier, quantity, conversion, tax, discount, serial, and real bundle-component changes, using the bundle shape produced by `PosCartService`.

## 2. Establish One Canonical Arithmetic Authority

- [x] 2.1 Consolidate `PosLineTotalAllocator` and `PosCartTotalsCalculator` into one shared minor-unit calculation path so derived values are never computed twice and contradicted.
- [x] 2.2 Implement unit-price override semantics: requested unit price becomes authoritative gross unit price, row gross is unit price × effective quantity/conversion, existing row discount applies exactly once, tax follows POS inclusive-tax rules, and the final row total is derived deterministically.
- [x] 2.3 Implement row-total override semantics: requested total is authoritative after row discount and before bill-level adjustment, with `gross = net + fixed_discount` and `gross = round(net / (1 - pct/100))`, `discount = gross - net`.
- [x] 2.4 Reject percentage row discounts below 0 or greater than or equal to 100 instead of clamping them.
- [x] 2.5 Ensure bill-level discounts apply after the row's authoritative net, with exact minor-unit reconciliation and no floating-point drift.
- [x] 2.6 Persist the canonical derived metadata needed so display, approval, draft, checkout, posting, receipt, and audit agree without contradictory reconstruction.
- [x] 2.6a Add one shared helper that clears every canonical derived field together (`line_total`, `line_gross_minor`, `line_discount_minor`, `line_net_minor`, `line_tax_minor`, `line_taxable_base_minor`); clearing only `line_total` leaves the rest trusted and a stale total survives.
- [x] 2.6b Refresh an applied `LINE_UNIT_PRICE_OVERRIDE` (preserve price and source, recompute metadata) and invalidate an applied `LINE_TOTAL_OVERRIDE` (revert to resolved standard pricing, remove all canonical fields) whenever quantity, row discount, tax context, or pricing source changes.
- [x] 2.6c Apply that rule at every mutation site: line updates, customer repricing to `TIER`/standard sources, serial appends that auto-increment quantity, and line merges (which sum quantities and therefore invalidate pre-merge metadata).
- [x] 2.6e Restore row-total invalidation through one canonical resolver that preserves line kind: bundle parent to the bundle sale price under `BUNDLE`, packed row re-packed under `PACKED`, tier row to its resolved tier price under `TIER`, ordinary row to its resolved price under `BASE`; preserve bundle identity, component snapshots, and informational allocations.
- [x] 2.6f Add focused tests for row-total invalidation on bundle, packed, tier-priced, and ordinary rows.
- [x] 2.6d Add focused tests: unit-price override then quantity increase; unit-price override then fixed and percentage discount changes; row-total override then quantity change; customer repricing after each override type; quantity increment from serial append; merge after customer change; and snapshot/draft output free of stale metadata.
- [x] 2.7 Apply overrides only to the billable parent for bundles, leaving component commercial amounts zero and informational allocations and fulfilment snapshots unchanged; preserve packed rows as one customer-facing row.
- [x] 2.8 Add focused arithmetic tests: unit price with no discount, with fixed discount, and with percentage discount; row total with fixed discount; the 10.000 at 10% reversal example; percentage ≥ 100 rejection; PKP and non-PKP rows; bill discount after row net; bundle parent residual exactness.

## 3. Add Separate Server-Side Mutation Contracts

- [x] 3.1 Add a unit-price request/endpoint/service method accepting line ID, requested unit price, reason, and optional approval token, validating numeric and non-negative input and persisting source `LINE_UNIT_PRICE_OVERRIDE`.
- [x] 3.2 Rework the row-total request/endpoint/service method to the same shape, persisting source `LINE_TOTAL_OVERRIDE` and reverse-deriving gross and discount metadata consistently.
- [x] 3.3 Reject client-provided source values, fingerprints, customer context, derived prices, discounts, tax, and totals on both endpoints; construct them server-side from the authoritative cart.
- [x] 3.4 Remove the product-ID fallback from approved execution on both paths so approvals resolve only an exact cart `line_id`.
- [x] 3.5 Keep the cart-wide endpoint non-mutating, returning HTTP 422 `FEATURE_RETIRED` with no active UI, JavaScript, snapshot, or approval state.
- [x] 3.6 Add focused coverage that the old ambiguous unit-price endpoint cannot bypass the new contract.

## 4. Serialize Cart Mutation and Make Execution Failure-Safe

- [x] 4.1 Add `PosCartMutationLock` keyed by `setting_id` + POS `session_id`, following the existing `Cache::lock` project idiom, with a bounded acquisition timeout and guaranteed release via `finally`.
- [x] 4.2 Apply the binding rule that **every** operation calling `putCart()`, `clearCart()`, or otherwise replacing/hydrating the same POS cart must acquire the lock — the guard set is every cart writer, not only writes deemed relevant to approval validity. Cover: unit-price and row-total overrides; quantity changes (`addLine`, `updateLine`); line removal (`removeLine`); serial assignment (`assignSerials`, `appendSerial`, `removeSerial`); customer changes (`updateCustomerSelection`); bill discounts (`updateBillDiscount`); **note updates (`updateNote`)**; staged-payment state changes that write the cart; cart clear (`PosCartService::clear`) and checkout clear (`FinalizePosCheckoutService`); transaction load/hydrate/reset (`PosTransactionService`); and the normalization write in `getSnapshot()`.
- [x] 4.2a Re-run an exhaustive code search for cart writers before implementation and guard any writer not listed above; note that the `payment_chain_{token}` session key is a separate key and out of scope.
- [x] 4.3 Return a retryable POS error on lock-acquisition timeout without changing the cart, consuming a token, or recording an audit.
- [x] 4.4 Record the `CACHE_DRIVER=file` per-server lock limitation as a deployment prerequisite for multi-server POS (Redis or database cache locks).
- [x] 4.4a Size the lock TTL above the longest guarded operation so ownership cannot lapse mid-callback, and prove with a test that a competitor cannot acquire while an operation outruns the former TTL.
- [x] 4.4b Keep re-entrance depth on a `scoped`-bound lock instance rather than process-wide static state (which a long-lived worker could leak across contexts); remove the `new PosCartMutationLock()` constructor defaults so the shared instance must be injected. Cover: collaborators in one scope share the lock, a fresh scope cannot inherit ownership, and owning one cart grants no bypass for another.
- [x] 4.4c Hold the cart mutation lock across checkout's authoritative span (snapshot capture → posting → clear) so concurrent mutation receives `CART_BUSY` and cannot leave already-posted lines in a surviving cart; the clear is then unconditional. Compare-and-set alone was rejected as insufficient for this span.
- [x] 4.4d Make the cart revision monotonic across clearing by storing the generation in its own session key, so a cart recreated after a clear can never reuse an earlier revision (ABA); expose it on snapshots as `cart_revision` read from the store. Cover with a capture → clear → recreate → stale-CAS test.
- [x] 4.5 Add one `PosRowOverrideExecutionCoordinator` serving both direct and supervised paths and both action types.
- [x] 4.6 Direct path: authorize → acquire cart lock → re-read cart → calculate → persist cart → write direct audit; compensate on audit failure before releasing the lock.
- [x] 4.7 Supervised path: acquire cart lock → open database transaction → `lockForUpdate()` the token row and approval request → revalidate token status and expiry while locked → re-read the authoritative cart → validate session, action type, target type, line ID, requester, requested value, and fingerprint → calculate → persist cart → conditionally consume token → write audit → commit.
- [x] 4.8 Replace the unconditional stale-model update in `consumeToken()` with a locked or conditional update requiring `consumed_at IS NULL`, asserting exactly one affected row.
- [x] 4.9 Enforce the session persistence boundary invariants: the lock is held through compensation or successful commit; compensation restores the exact pre-operation cart (not a partial field revert); the token/request transaction rolls back if consumption or audit fails; the cart is restored before the lock is released.
- [x] 4.9a Guarantee restoration independently of rollback: run rollback in its own guarded step so a rollback failure cannot skip cart restoration.
- [x] 4.9b Never swallow restoration failure: retry restoration with a bounded strategy, verify the stored cart against the original business content, log a critical structured error, and raise a distinct `CART_COMPENSATION_FAILED` exception with the original failure attached as root cause.
- [x] 4.9c Re-read the stored cart after `putCart()` and use that persisted representation for the audit payload, the coordinator return value, and post-persistence verification, since `putCart()` stamps a fresh generation the in-memory array does not carry.
- [x] 4.10 Compare submitted requested values against approved values exactly in minor units and reject mismatches without consuming the token, rather than substituting the approved value.
- [x] 4.11 Ensure failed validation, calculation, and cart persistence never change the cart, consume a token, or record a successful audit; and that failed token or audit persistence never leaves the override applied.
- [x] 4.12 Add focused tests: approved unit-price execution, approved row-total execution, cross-action token rejection, requested-value mismatch without consumption, wrong session/line/requester/fingerprint without consumption, retry after rejection, replay after success, concurrent consumption with exactly one winner, simulated cart-store failure, simulated token/audit failure restoring the cart, direct-path audit failure compensation, lock-acquisition timeout, a concurrent competing cart mutation blocked during override execution, and an unrelated cart write such as a note update that waits for the override lock and is preserved after compensation rather than erased by the snapshot restore.

## 5. Integrate Authorization, Approval Payloads, and Audit

- [x] 5.1 Authorize both active actions through `pos.overrides.price` with Super Admin bypass; return the standard `APPROVAL_REQUIRED` outcome otherwise.
- [x] 5.2 Derive the current unit price from authoritative line state and the current final row total through the canonical totals calculator, never as quantity × unit price, storing both in minor units.
- [x] 5.3 Use one shared server-side builder for approval payloads so creation and execution cannot interpret values differently.
- [x] 5.4 Ensure the approval queue delta compares unit price against unit price for `LINE_UNIT_PRICE_OVERRIDE` and final row total against final row total for `LINE_TOTAL_OVERRIDE`.
- [x] 5.5 Record successful direct and supervised executions with action type, session and line ID, source and requested values in minor units, source and resulting row totals where useful, reason, fingerprint, requester, authorizer or supervisor, timestamp, and result — only after mutation and consumption succeed.
- [x] 5.6 Expose `requested_unit_price` and `requested_line_total` in the target row's pending approvals keyed by line and action type, keeping quantity and removal states independent.
- [x] 5.7 Invalidate pending approvals for both active override actions on any relevant line or cart mutation.
- [x] 5.8 Add focused approval tests for direct execution of both actions, restricted request creation, approval/rejection/cancellation, action coexistence on one row, discounted-row source total correctness, and correct value labelling per action.

## 6. Correct the Row-Action UI

- [x] 6.1 Render both `Ubah Harga Satuan` and `Ubah Total Baris` in each eligible row's action area, visually distinct and unambiguous, and remove the mislabelled single control.
- [x] 6.2 Build two separate modals with distinct IDs, form state, endpoints, JavaScript handlers, labels, and error handling; retire the ambiguous `price_override` DOM identifiers rather than reassigning them.
- [x] 6.3 Show the selected product and row identity plus the relevant current value in each modal — current unit price in one, current authoritative row total in the other.
- [x] 6.4 Keep both actions out of the cart and payment grand-total area, and keep the cart-wide `Ubah Total` action absent.
- [x] 6.5 Mirror Sales and Purchase terminology, input formatting, reason handling, permission/approval UX, and arithmetic semantics where applicable; document the deliberate modal-versus-inline deviation.
- [x] 6.6 Keep ordinary, packed, and billable bundle-parent rows editable at row scope while bundle components expose neither action.
- [x] 6.7 Key client approval state by both line ID and action type so neither control displays the other's state.
- [x] 6.8 Update the supervisor approval queue to show row product, quantity, source value, requested value, delta, requester, reason, and target-line context per action type, rendering historical action types read-only.
- [x] 6.9 Add focused UI/routing coverage: both row actions render, the two modals are distinct, neither appears as a cart-wide action, and the legacy cart-wide endpoint remains non-mutating.
- [x] 6.10 Display the authoritative row total (`line_net_before_bill`) beside `Ubah Total Baris` so the row figure matches the value the modal edits; show the post-bill-discount amount only under an explicit label.
- [x] 6.11 Name the active row endpoint `/lines/{lineId}/line-total-override` so it cannot be confused with the retired `/cart/total-override`.
- [x] 6.12 Escape every payload-derived string in the approval queue before interpolation (reason, product name, identifiers, requester, unknown action types, legacy payload text); cover with a stored-XSS test using `<img src=x onerror=alert(1)>`.

## 7. Reconcile Checkout, Snapshots, and Persistence

- [x] 7.1 Make cart totals, payment validation, receipts, draft snapshots, canonical hashes, and finalize payloads consume the canonical persisted values rather than recomputing from a rounded unit price.
- [x] 7.2 Allocate one overridden row total deterministically across only that row's fulfilment/source-owner chunks with exact minor-unit reconciliation and unchanged source-owner PKP classification.
- [x] 7.3 Preserve billable bundle-parent authority, zero component commercial amounts, informational component snapshots, packed-row identity, and original captured snapshot behavior through posting.
- [x] 7.4 Ensure posted Sale detail/header totals, tax/DPP, payments, receipts, and idempotent replay reconcile exactly for both override types.
- [x] 7.5 Run the directly affected existing tests for POS override authorization, totals calculator/allocation, draft round-trip and hash, split-owner checkout/posting, checkout idempotency, and receipt rendering where changed monetary fields are consumed.
- [x] 7.6 Persist an explicit `line_total_minor` for active overrides and prioritise it in the totals calculator, snapshot mapper, and draft hydration, retaining the ambiguous `line_total` only as a legacy fallback.
- [x] 7.7 Add integration coverage that drives the REAL flow — PosCartService, the actual split planner and posting adapter, persisted Sale records, and PosReceiptService — rather than mirroring planner arithmetic in test helpers. Cover: non-divisible row total split across two owners summing exactly; unit-price override reconciling through posting; bill discount kept distinct from the row override; draft save/reload/finalize identity; receipts reporting gross, row discount, bill discount, and charged total for both override types; and idempotent replay not posting the override twice.

## 8. Retire Cart-Wide Total Mutation Safely

- [x] 8.1 Keep the cart-total mutation route/controller/service entry point disabled so no client can create or execute a new cart-wide override; verify rejection leaves the cart unchanged.
- [x] 8.2 Remove residual cart-level allocation, applied-total restoration, and snapshot code that exists only for retired `TOTAL_PRICE_OVERRIDE` behavior, while preserving historical deserialization and reporting support.
- [x] 8.3 Stop accepting new `TOTAL_PRICE_OVERRIDE` approval requests and ensure pending legacy requests cannot mutate a current cart.
- [x] 8.4 Keep `pos.overrides.total-price` deprecated and absent from active registry, assignment, bundle, and capability surfaces; confirm `pos.overrides.price` governs both active actions without destructively deleting live role assignments.
- [x] 8.5 Add focused retirement tests proving no cart-wide UI/action/API remains, the legacy permission does not authorize a mutation, historical audit rows still render, and supported permission/runtime matrices remain in parity.

## 9. Focused Verification and Reporting

- [x] 9.1 Run only the directly affected POS test files and filters; do not run the full application suite or an unfiltered `php artisan test`.
- [x] 9.2 Verify both row actions end-to-end through drafts, checkout, posting, receipts, and approval audit — not merely that the original five test files pass.
- [x] 9.3 Validate `replace-pos-cart-total-with-line-total-override` with strict OpenSpec validation and resolve all artifact/spec errors.
- [x] 9.4 Produce the completion report: OpenSpec artifacts updated, exact files changed, UI placement and labels, endpoint/action/permission mapping, the cart-lock/transaction/compensation sequence with its boundary invariants, proof of submitted-versus-approved comparison, canonical source-total calculation, canonical persisted arithmetic fields, fixed and percentage examples in minor units, proof of a single concurrent winner, proof that failure leaves no partial effect, every focused test command with test and assertion counts, and strict validation output.
