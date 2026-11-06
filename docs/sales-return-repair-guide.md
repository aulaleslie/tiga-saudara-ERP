# Sales Return / POS Return Repair Guide

Tracks OpenSpec change `repair-sales-return-lifecycle-preserve-pos`. Sections are
added phase by phase; this version covers **Phase 1 — POS stabilization**.

## Phase 1: POS Return baseline

### Gate command

```bash
php artisan test Modules/Pos/Tests \
  --filter='POSReturn|PosReturn|PosRowTotalRounding|POSReceiptReturnProjection|GlobalPosMultiPayment|GlobalPosPayment|PosReturnRounding'
```

| Point in time | Result |
|---|---|
| Before Phase 1 (`Modules/Pos/Tests/Feature --filter='POSReturn\|PosReturn\|PosRowTotalRounding'`) | 27 failed (+1 rounding case) |
| After Phase 1 (gate command above) | **493 passed, 0 failed (2833 assertions)** |

The earlier broad "Return" substring filter also selects unrelated POS tests; it is not a
clean return-only gate. Use the gate command above.

### Outside the gate (unrelated baseline)

Running the whole `Modules/Pos/Tests/Feature` directory: 352 failed before, 328 after
(55 failing classes). These are session, checkout, cart, tax and receipt-UI tests
unrelated to returns and are **not** addressed by this change.

Three return-named classes fail only in the full-directory run and pass when run alone
(`POSReceiptReturnProjectionTest`, `POSReturnBundleCarrierRowCashReturnCompletenessTest`,
`POSReturnSplitOwnerCarrierRowRegressionTest`). They fail identically on the untouched
baseline, so this is pre-existing order-dependent test pollution, classified *unrelated
baseline*; they are covered by the gate run, where they pass.

### Defects fixed (application code)

| Area | Defect | Fix |
|---|---|---|
| `PosReturnApprovalPreviewPlannerService::buildLinePlan` | Cash valuation chain overwrote the canonical replacement commercial amount (fell through to `expected_cash_amount = 0`). | Cash overrides apply only to cash resolutions (and component lines). Replacement lines keep their resolved amount. |
| `PosReturnSubmissionService::store/update` | Valuation selections were built from raw payload, so a header-default omitted resolution was valued as *none* although validation resolved it to cash. | `buildNormalizedValuationSelections()` uses validated line intent; explicit *none* stays none. |
| `PosReturnSubmissionService` fallback valuation | Referenced undefined `$cumQty` inside a swallowed exception, silently substituting the line total. | `$cumQty` is computed from the committed state. A failure no longer substitutes an amount: the line is stored with `expected_cash_amount = 0` **and** `line_meta.valuation_error`, and `submitDraftForApproval` rejects it with an actionable message. Legitimate zero-price returns carry no flag and still submit. |
| `PosReturnRoundingValuationService::resolveCommercialBaseline` | Without a POS transaction line, the baseline was re-derived from the already-reduced sale detail, so a second successive partial return valued at 0 (commitment counted twice). | The sale's frozen `return_rounding_context` baseline wins over the live, reduced sale detail. |

### Fixture and assertion corrections (tests)

- **Component composition / serial normalization**: planner fixtures now carry the total
  component composition; serial returns normalize to one unit. Priced-component behavior
  keeps separate coverage (`it_keeps_priced_component_commercial_amount_for_linked_component_targets`).
- **Actionable error contract**: serial-claim and missing-component assertions match the
  current messages; no-save / no-stock-change assertions are retained.
- **Monetary preconditions** (19 previously blocked cases): fixtures now describe a source
  that the stale-value guard can value. Guards were **not** relaxed. Re-derived:
  - Source charge must match the returned amount (2 units at 600; returning one is 600).
  - A bundle's commercial value is the parent charge plus priced `SaleBundleItem` shares;
    each owner refunds its own share (parent 1000 + component owner 300 = 1300).
    Zero-priced components carry 0 refund; the parent carries the bundle price.
  - Hand-built split-owner fixtures needed a real POS transaction line, checkout and
    checkout-sale links, and explicit `pos_transaction_line_id` binding when the same SKU
    appears twice (bundle parent and replacement).
  - Component return details reference their own component `PosReturnLine`.
  - Payment-split case: payments (900 + 600 + 200 = 1700) exceed the reduced total (1400),
    so the last payment is invalidated and the previous one split.
  - **HPP vs refund**: cost-reversal fixtures return a whole bundle (parent refund 300,
    components 0 refund); component HPP stays the immutable 60 snapshot x 2 returned = 120.
- **Successive partial returns** (`PosRowTotalRoundingTest`): the manual `COMPLETED`
  simulation is replaced by real approved execution (submit, plan, synchronize, execute).
  It asserts remaining entitlement, the frozen increment (100 despite a later setting
  change to 500), committed 2 / 26000 and the source residual after each return. This
  test is also the reproducing regression for the baseline defect above.

### Review follow-ups (closed)

- **Primary valuation errors**: `PosReturnRoundingValuationService::evaluateDraftValuation` no
  longer substitutes quantity x unit price when valuation throws. It returns refund 0 plus a
  per-line `valuation_error`, which `PosReturnSubmissionService` stores in
  `line_meta.valuation_error`; submission is then rejected. Tests:
  `it_blocks_submission_when_primary_draft_valuation_fails` and
  `it_keeps_a_legitimate_zero_price_return_valid_and_unflagged`.
- **Masked defect exposed**: removing the evaluator's silent fallback revealed that the
  parent-only branch rebuilt its baseline from the already-reduced sale detail (draft of a
  second bundle return valued 890,000 instead of 880,000). The baseline now restores the
  original quantity from the frozen context or returned history. Asserted in
  `it_maintains_cumulative_rounding_history_for_sequential_bundle_returns`.
- **Component replacements**: the planner's cash-valuation chain now applies to cash
  resolutions only. A component replacement keeps its resolved commercial amount, or its
  priced bundle-item allocation, and never the zero expected cash. Test:
  `it_keeps_component_replacement_amounts_out_of_cash_valuation` (priced and unpriced).
- **Fixture decision resolved**: the shared-line-share planner fixture uses an unpriced
  component; priced components have their own test.
