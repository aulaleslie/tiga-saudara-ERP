## 1. Checkout State and Validation

- [x] 1.1 Introduce a checkout-level debt context in `pos-staged-payment.js` that retains debt mode, payment term, customer resolution, payment chain, and outstanding amount independently of the current method/amount/reference inputs.
- [x] 1.2 Split payment-input reset from full checkout reset so partial staging and supervisor approval do not clear debt context, while success and explicit abandonment still clear all checkout state.
- [x] 1.3 Refactor staged-payment validation into mode-aware decisions: preserve normal settlement rules, permit zero debt down payment without a method, permit positive partial cash/non-cash debt payments below the grand total, enforce references, and reject debt payments at or above the grand total.
- [x] 1.4 Route a successfully staged debt down payment to debt final confirmation with the remainder treated as outstanding, instead of requesting additional stages or clearing the selected term.

## 2. Final Transaction Confirmation

- [x] 2.1 Update the staged checkout modal markup with a distinct final transaction summary showing total, paid amount, change or debt outstanding, and debt customer/payment-term details when applicable.
- [x] 2.2 Add one finalization gateway in `pos-staged-payment.js` and replace every direct automatic-finalize branch, including exact payment, overpayment, recovered zero remainder, and zero-down-payment debt.
- [x] 2.3 Make the final confirmation cancel action preserve cart, staged payments, and debt state, and restore focus to the staged checkout surface without leaving duplicate Bootstrap backdrops.
- [x] 2.4 Make final confirmation proceed construct one canonical payload and submit one locked, idempotent finalize request; prevent double clicks and reuse the attempt's idempotency key for an in-attempt retry.
- [x] 2.5 Integrate the finalization gateway with `ApprovalManager` so approval-required, pending, approved, rejected, and expired-token outcomes preserve the same debt/payment context and require an explicit token-bearing retry from the final summary.

## 3. Packed Receipt Data Accuracy

- [x] 3.1 Extend packed pricing-basis construction to carry the chosen conversion-unit and base-unit display labels, preferring `short_name` and falling back to `name` without changing stored product prices.
- [x] 3.2 Propagate both unit labels through `PackedLinePricingService`, cart updates and repricing, transaction snapshot persistence, and draft hydration without changing authoritative minor-unit totals.
- [x] 3.3 Refactor `PosReceiptService` to build packed breakdown presentation in one shared path for completed and draft receipts, converting `box_price_applied` and `loose_price_applied` from minor units to Rupiah exactly once.
- [x] 3.4 Replace `[K]` and first-letter placeholders with full snapshotted unit labels in receipt output, with historical fallback through the line conversion and configured product packing conversion and a neutral full-name fallback only when necessary.
- [x] 3.5 Confirm historical transaction metadata remains read-only and that non-packed, bundle, serial, discount, payment, and grand-total receipt behavior is unchanged.

## 4. Thermal Receipt Layout

- [x] 4.1 Define fixed layout columns for thermal widths (Product: min 40%, Qty: 15%, Unit/Price: 20%, Total: 25%) ensuring adequate horizontal alignment for multi-line items.
- [x] 4.2 Apply shared non-wrapping CSS styles to layout containers rather than inline attributes, enforcing uniform boundaries for itemized grids.
- [x] 4.3 Implement bounded compact amount styling that scales numbers down instead of wrapping when transaction digits exceed typical widths.
- [x] 4.4 Clean up redundant inline width styles, `<span>` tags for line-breaks, and manual padding throughout `receipt.blade.php`.

## 5. Regression Verification

- [x] 5.1 Add deterministic frontend tests for normal exact/over/partial transitions, cancellation, recovered zero remainder, zero/partial debt validation, debt-state persistence, and duplicate-finalize prevention using existing tooling or Node's built-in test runner without a new runtime dependency.
- [x] 5.2 Extend POS debt feature coverage for zero and partial cash/non-cash down payments, term/customer requirements, direct permission, supervisor approval retry, idempotency, split allocation, and unchanged normal full payment.
- [x] 5.3 Extend packed pricing and snapshot tests to verify conversion/base-unit labels survive add, quantity update, customer repricing, save/load, and completed checkout paths.
- [x] 5.4 Extend receipt tests to assert that `21000000` minor units render as `Rp210.000`, that `Rp21.000.000` is absent for that breakdown, and that actual configured unit labels appear in both draft and completed receipts without `[K]` or derived initials.
- [x] 5.5 Add receipt rendering assertions for large item, grand-total, payment, and change values plus long labels, verifying complete digits and the fixed non-wrapping monetary classes.
- [x] 5.6 Run focused POS checkout/debt/receipt/packing tests, then run `composer test:fresh-sqlite` (or the broadest feasible POS suite) and record any unrelated pre-existing failures.
- [x] 5.7 Perform 72 mm print-preview UAT for normal payment, overpayment, zero debt, partial debt, approval-required debt, packed DUS/RIM output, and representative large totals.
