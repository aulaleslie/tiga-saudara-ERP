## 1. Establish the Focused Regression Baseline

- [x] 1.1 Run the failing `SplitBundleTransactionTest::test_split_bundle_transaction_correctly_calculates_ownership_and_prices` alone and record the current component subtotal and tax assertion behavior.
- [x] 1.2 Trace the planner child `allocated_minor` values through `SplitPosCheckoutPostingAdapter` into `InlinePosCheckoutPostingAdapter`, confirming the amount and quantity contract before changing persistence.
- [x] 1.3 Identify directly affected receipt, transaction-detail, return, and report consumers that read `SaleBundleItem.price`, `sub_total`, or `informational_item_price` and document which representation each treats as authoritative.

## 2. Persist Exact POS Component Allocations

- [x] 2.1 Update POS split posting so each fulfilled `SaleBundleItem.sub_total` receives the exact sum of its planner-authored allocated minor units and does not reload live bundle or product pricing.
- [x] 2.2 Persist a component unit `price` consistent with fulfilled quantity while keeping exact `sub_total` authoritative when rounding prevents exact multiplication.
- [x] 2.3 Preserve component-only logical parent details with zero parent quantity/unit price and the owner-group subtotal, and preserve parent-plus-component groups with parent residual unit price and the complete group subtotal.
- [x] 2.4 Add or update focused split transaction tests for the `110000 = 85000 + 25000` cross-owner fixture, same-owner nested allocation, multiple quantities, rounding-sensitive values, and parent price overrides.

## 3. Preserve Tax and Customer Presentation

- [x] 3.1 Correct the canonical split transaction tax assertion so a PKP POS owner is taxed only on its own owner-group allocation and source-owner component Sales remain non-tax.
- [x] 3.2 Verify component allocation persistence does not add bundle-item tax to Sale headers, checkout summaries, or payments a second time.
- [x] 3.3 Add focused receipt and POS transaction-detail assertions proving the parent displays the full captured bundle price, components remain zero/free, and the customer total appears once.

## 4. Protect Whole-Bundle Returns and Accounting

- [x] 4.1 Verify return eligibility continues to reject component-only bundle returns without creating any Sale, return, payment, dispatch, inventory, or HPP mutation.
- [x] 4.2 Add a focused whole-bundle cash-return scenario proving the customer refund uses the original full captured bundle amount while internal parent/component reversals use the persisted original allocation and owner/location lineage.
- [x] 4.3 Add a partial-by-whole-bundle-quantity scenario proving one of multiple bundles reverses one complete composition and does not reload current bundle prices, product prices, or average costs.
- [x] 4.4 Verify the shared HPP aggregate continues using parent and component cost snapshots independently and never uses the new component revenue price/subtotal as cost.
- [x] 4.5 Add a focused revenue/report assertion proving a SaleDetail subtotal and its nested SaleBundleItem allocation are not summed twice.

## 5. Atomicity, Replay, and Focused Release Gate

- [x] 5.1 Exercise a failure in a later owner group and assert the database transaction leaves no partial Sales, bundle items, payments, dispatches, inventory movements, or cost snapshots.
- [x] 5.2 Replay a successful split checkout with the same idempotency key and assert no duplicate rows or amount changes.
- [x] 5.3 Run only the touched split-bundle transaction/pricing, receipt/detail, POS bundle return, Sale HPP aggregate/report, atomicity, and idempotency tests; do not run full module or repository suites.
- [x] 5.4 Run `openspec validate resolve-split-bundle-subtotal-reconciliation --strict` and record the focused test results and any independently reproduced out-of-scope failures.
