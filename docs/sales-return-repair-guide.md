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

## Phase 2: Workflow ownership boundary

### Rule

A sale belongs to exactly one return workflow. `App\Support\SalesReturn\SalesReturnWorkflowResolver`
classifies every sale and return:

| Class | Meaning | Standard Sales Return | POS Return |
|---|---|---|---|
| `standard` | No POS link anywhere | Allowed | n/a |
| `pos` | POS checkout (legacy `pos_checkouts.sale_id`), split link (`pos_checkout_sales.sale_id`) or any linked POS return | **Blocked** (read only) | Allowed |
| `mixed` | POS-origin sale that also has historical non-POS standard returns | **Blocked** | **New claims blocked** |

A null `pos_return_id` alone never proves an ordinary sale: both checkout link shapes are
checked. Historical rows are never rewritten by classification or by any blocked action.

### Enforcement points (all server-side)

- Lookup: `SaleReturnEligibilityService::isSaleEligible` excludes non-standard sales; the
  search dropdown shows POS guidance when a search only matched POS sales.
- Create/edit: `handleSaleSelected` (ignores client-supplied rows), create `submit`
  (source sale locked first, ownership re-checked under the lock), edit `mount` and `submit`.
- Routes: middleware `sales-return.standard` (`EnsureStandardSalesReturn`) on approve, reject,
  receive, archive, settlement item approve/reject, dispatch form/request/approve/reject/item;
  the same guard on the resource `edit/update/destroy` and the legacy payment adapters.
  Rejections are HTTP 409 with guidance, even for a user holding both permission sets.
- Settlement Livewire form: renders read-only for POS-owned returns; `submit`, `submitLine`,
  `resetLine` and `updatedSettlementLines` re-check authoritative state.
- Lifecycle sync (`SaleReturnLifecycleSyncService`): explicit branches. POS returns derive
  status and archival from remaining active quantities and complete without settlement rows
  or inspection. Standard branches are no-ops on non-standard sales.
- POS: `PosReturnSubmissionService` rejects new POS claims (`store`, `update`,
  `submitDraftForApproval`) when a source sale has mixed history.

### Operator guidance

- "Penjualan ini berasal dari POS" / "dikelola oleh Retur POS": use **POS > Retur** (`/pos/returns`).
  Reading the return in Sales Returns still works.
- "riwayat retur campuran": the sale has both POS and standard return records. Nothing was
  changed. Ask an administrator to review the history; a read-only audit command arrives in a
  later phase (group 8). No automatic repair exists or is planned.

### Pre-existing defects noticed (not changed here)

- `SaleReturnDetail::selectableSettlementMethods()` is missing, so the standard settlement view
  fails to render (task 3.1).
- The standard `show` page references an undefined `saleReturnGoods` relation on `SaleReturn`.
- `PRAGMA foreign_keys = OFF` in older tests is a no-op inside `RefreshDatabase`; new tests use
  `PRAGMA defer_foreign_keys = ON` (task 3.2 covers the older ones).

### Verification (phase 2)

| Check | Result |
|---|---|
| POS gate (same command as phase 1) | **495 passed, 0 failed** (493 + mixed-history store regression + pending-execution regression) |
| Standard filter `--filter='SalesReturn\|SaleReturn\|SaleReturns'` | 19 failed / 57 passed; baseline was 19 failed / 34 passed, **identical failing set** (owned by group 3) |
| `Modules/Pos/Tests/Feature` | 328 failed, unchanged vs end of phase 1 |
| `tests/Feature/Livewire` | 64 failed, identical to pre-change baseline |

### Review follow-ups (closed)

- **Settlement form identity**: `saleReturn`, `saleReturnId` and `isReadOnly` are `#[Locked]`; the guard
  compares the hydrated model with the authoritative row. `resetLine`/`submitLine` resolve every
  settlement through its parent (`sale_return_id` = this return, row locked) and take status from
  the database row, never from the hydrated line; a detail that does not belong to the return is
  rejected. The edit form's `saleReturn` is locked too.
- **Mixed history in sync**: `SaleReturnLifecycleSyncService` checks mixed classification before
  choosing either mutation branch, in status sync, completion rollup and archival. A sale with both
  standard and POS-linked returns no longer changes status (previously `DISPATCHED` -> `RETURNED`).
- **Already-pending POS returns**: `PosReturnLifecycleService` revalidates mixed history under the
  source-sale locks in `approve`, `executeApprovalFromPreview`, `receive`, `settlePaymentReturn` and
  `dispatchReplacement`. `reject`/`cancel`/`archive` stay allowed (they release claims).
  The regression was verified to fail with the guard disabled.
- Note: the new classification tests defer foreign-key checks; they prove ownership rules, not
  relational integrity.
