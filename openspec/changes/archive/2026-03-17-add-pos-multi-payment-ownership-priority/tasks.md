## 1. Schema and Contract Foundations

- [x] 1.1 Add additive persistence for checkout payment rows (one row per payment entry) and payment-to-split-group allocation rows with required indexes/foreign keys.
- [x] 1.2 Add/adjust Eloquent models and relations for checkout payment rows and allocation mappings.
- [x] 1.3 Extend finalize request validation to accept `payments[]` while keeping compatibility for legacy single `payment` input.
- [x] 1.4 Add canonical payload normalization for multi-payment input (method, amount, reference, stable order identity).

## 2. Multi-Payment Validation and Idempotency

- [x] 2.1 Refactor checkout payment normalization/validation to support multiple rows, reference requirements, and exact total reconciliation.
- [x] 2.2 Extend idempotency payload hashing to include canonicalized multi-payment composition.
- [x] 2.3 Ensure idempotent replay returns identical payment composition and allocation structures without side effects.
- [x] 2.4 Keep top-level compatibility response fields (`sale_id`, `sale_payment_id`, `dispatch_ids`) mapped from first deterministic split group.

## 3. Ownership-Priority Allocation and Split Posting

- [x] 3.1 Implement ownership-priority payment allocation service (non-cash to terminal-owner share first, proportional fallback, then cash to residual).
- [x] 3.2 Enforce matrix reconciliation checks: per-payment total, per-group total, and checkout grand-total equality in minor units.
- [x] 3.3 Update split posting adapter to consume multi-payment allocations and emit deterministic payment-allocation outputs.
- [x] 3.4 Update inline posting flow to create per-group sale payment rows from allocated method lines while preserving source-owner sale creation behavior.
- [x] 3.5 Persist payment allocation traceability so replay/reporting/reconciliation can reconstruct method-to-group funding.

## 4. POS Checkout Modal UX (Composer)

- [x] 4.1 Replace single-method state in `sell.blade.php` with payment-composer state (multiple rows, add/remove/edit operations).
- [x] 4.2 Separate method search/picker rendering from selected-payment list to eliminate stacking/collision behavior.
- [x] 4.3 Add real-time aggregate summary (`total_paid`, `remaining`, `change`) and disable submit until validation passes.
- [x] 4.4 Submit new `payments[]` payload from checkout modal, with fallback handling for compatibility path if required.

## 5. Downstream Consistency (Cash, Receipt, Reports, Reconciliation)

- [x] 5.1 Update cash event/expected-cash logic to use summed cash payment components from payment entries.
- [x] 5.2 Update supervisor finalization summary calculation and formula display to use payment-entry cash totals.
- [x] 5.3 Update receipt projection/template to display mixed-method payment breakdown coherently.
- [x] 5.4 Update reporting payment-method aggregation to source from payment entries (mixed checkout contributes to multiple methods).
- [x] 5.5 Update reconciliation cash/non-cash and posted-payment comparisons to use payment-entry source of truth.

## 6. Test Coverage and Rollout Safety

- [x] 6.1 Add feature tests for multi-payment finalize success (mixed cash/non-cash, required references, change calculation).
- [x] 6.2 Add feature tests for ownership-priority allocation correctness across multi-owner split groups.
- [x] 6.3 Add idempotency tests for replay stability with multi-payment payloads and mismatch detection for altered payment composition.
- [x] 6.4 Add regression tests for legacy compatibility fields and existing client response assumptions.
- [x] 6.5 Add report/reconciliation/session-finalization regression tests to verify cash vs non-cash totals under mixed-method checkouts.
- [x] 6.6 Run targeted manual QA on `/pos/sell` for picker-collision elimination and cashier usability under rapid entry.
