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

## Group 3: Standard settlement surfaces and server guards

### What changed

| Area | Change |
|---|---|
| Settlement choices (3.1) | `SaleReturnDetail::selectableSettlementMethods()` restored: repair/replacement (`REPAIR`), cash return (`CASH_REFUND`), cannot process (`UNPROCESSED`). `settlementMethods()` keeps every historical label (modify sale, customer credit). New submissions are validated against the selectable set only. |
| Fixtures (3.2) | The fourteen setup failures were invalid fixtures (missing customer/location/settings, unreceived "Awaiting Settlement" returns, unpriced sale lines). They now create real related records with foreign keys **enabled**; no constraint is switched off. |
| Services (3.3) | `StandardReturnGuard` (lock order **source sale -> header -> settlement row**, business scope, state transitions), `StandardReturnClaimService` (authoritative quantity/lineage/serial/price at claim time), `StandardDispatchRequestService` (one set of dispatch prerequisites). Controllers and Livewire forms are adapters. |
| Resubmission and dispatch (3.4) | Submit/reset read the persisted row: only `DRAFT`/`REJECTED` are editable. Bulk and single-item dispatch share one service; the bulk request is all-or-nothing and never skips a row. |
| Replay safety (3.5) | Locked re-read of header/row state before every effect; payout and credit rows carry a unique `sale_return_item_settlement_id` anchor; notifications run after commit. |

### Defects found and fixed along the way

- `SaleReturn` lost its `creating` hook in commit 90694353, so **standard returns could not be created** (`reference` is NOT NULL). Restored without overwriting an explicit reference (POS).
- The same commit dropped `saleReturnGoods()` and `receivedBy()` (the show/settlement pages and a job use them) and omitted `archived_at/archived_by` from `$fillable`, so **archiving silently did nothing**.
- The edit form lost `editingSaleReturnId` between Livewire requests, so a return counted its own claim as "already returned".
- Entitlement used a client-supplied `original_quantity`; price/total came from the client. Both are now recomputed under lock.
- Serial reservation lookups used JSON operators that do not exist on SQLite and compared `approval_status` case-sensitively (`REJECTED` is stored upper case), so rejected returns kept blocking serials. Both are now portable and case-insensitive.

### Standard state transitions and permitted actions

Business scope: the document's `setting_id` must equal the active `session('setting_id')`; otherwise HTTP 403. POS-owned and mixed-history returns are rejected before any of this (phase 2).

**Header (`approval_status` / `status`)**

| # | From | Action (permission) | To | Test |
|---|---|---|---|---|
| T1 | Pending / Rejected | edit (`saleReturns.edit`, form) | same | `StandardClaimGuardsTest::edit_recomputes_entitlement...`, `StandardStateTransitionsTest::approved_returns_cannot_be_edited` |
| T2 | Pending Approval | approve (`saleReturns.approve`) | Awaiting Receiving | `pending_approval_to_awaiting_receiving_on_approve` |
| T3 | Pending Approval | reject (`saleReturns.approve`) | Rejected (editable again) | `pending_approval_to_rejected_on_reject_and_rejected_returns_stay_editable` |
| T4 | not received | archive / delete (`saleReturns.archive` / `.delete`) | archived / deleted | `archive_and_delete_are_allowed_only_before_receiving` |
| T5 | Awaiting Receiving | receive (`saleReturns.receive`) | Awaiting Settlement | `awaiting_receiving_to_awaiting_settlement_on_receive`, `receive_is_refused_for_pending_returns` |
| T6 | Awaiting Settlement | all rows `APPROVED`/`DISPATCHED` | Completed (rollup) | `submitted_cash_refund_row_becomes_approved_and_completes_the_return` |

**Settlement row (`SaleReturnItemSettlement.status`)** (requires an approved **and received** header)

| # | From | Action (permission) | To | Test |
|---|---|---|---|---|
| S1 | Draft / Rejected | submit (`saleReturnSettlements.submit`) | Submitted | `StandardSettlementGuardsTest::a_draft_or_rejected_row_can_be_submitted_and_reset...` |
| S2 | Submitted | approve repair/cannot process (`.approve`) | Approved Awaiting Dispatch | `submitted_repair_row_becomes_approved_awaiting_dispatch` |
| S3 | Submitted | approve cash refund (`.approve`) | Approved (+ one payout) | `submitted_cash_refund_row_becomes_approved_and_completes_the_return`, `approving_a_cash_refund_twice_pays_out_once` |
| S4 | Submitted | reject (`.approve`) | Rejected | `submitted_row_can_be_rejected_and_then_reset_to_draft` |
| S5 | Rejected | reset (`.submit`) | Draft | same test |
| S6 | Approved Awaiting Dispatch | request dispatch (`.dispatchRequest`/`.dispatch`) | Dispatch Requested | `dispatch_request_can_be_rejected_back_to_awaiting_dispatch`, `a_valid_dispatch_request_moves_to_requested...` |
| S6b | Dispatch Requested | reject dispatch (`.dispatchApproval`) | Approved Awaiting Dispatch | `dispatch_request_can_be_rejected_back_to_awaiting_dispatch` |
| S7 | Dispatch Requested | approve dispatch (`.dispatchApproval`) | Dispatched | `approved_dispatch_of_the_same_serial_marks_the_row_dispatched` |
| S8 | Approved / Dispatched | anything | terminal | `terminal_rows_accept_no_further_settlement_actions`, `approved_dispatching_or_dispatched_rows_cannot_be_resubmitted...` |

Rejected by design (each has a test): unapproved or unreceived settlement; stale or excess claims; forged dispatch line, product, detail, serial; foreign serials; reserved serials; legacy or unknown methods; blank/whitespace "cannot process" reasons; nominal above the document value; another business's documents; replayed approvals; stale hydrated models.

### Notes for operators and reviewers

- Dispatch approval still moves stock the way it did; custody-based dispatch is group 5.
- Receiving still adds good stock; held custody and inspection are group 4.
- The new unique indexes are additive. The settlement-line index is only created when existing data has no duplicates; otherwise locked revalidation remains the guard and nothing is rewritten.

### Verification (group 3)

| Check | Result |
|---|---|
| POS gate (same command) | **495 passed, 0 failed** |
| Standard filter + new classes | **124 passed, 0 failed** (baseline 19 failed / 34 passed) |
| `Modules/SalesReturn` | 19 passed |
| `Modules/Pos/Tests/Feature` | 328 failed, unchanged |
| `tests/Feature/Livewire` | 56 failed (was 64; the 8 fixed are the sales-return ones, no new failures) |
| Other areas that read sale returns (reports, archiving, status labels, 15 files) | all passed |
| Negative controls | disabling the guard's state checks turns the suite red; disabling only the locked checks fails the stale-model tests |

### Group 3 review follow-ups (closed)

- **Aggregate claims**: `StandardReturnClaimService` now consumes entitlement across the whole request (two rows for one dispatch line share its remaining quantity) and allows a serial only once per request. Same service backs create and edit.
- **Action permissions inside Livewire mutations**: create `submit` needs `saleReturns.create`, edit `submit` needs `saleReturns.edit`, and every settlement mutation (field edit, `submitLine`, `resetLine`, `submit`) needs `saleReturnSettlements.submit`. UI visibility is not authorization.
- **Notifications**: all standard notifications are `DB::afterCommit` callbacks, so they fire after the **outermost** commit; an enclosing rollback drops them. (Under `RefreshDatabase` the commit signal never occurs, so the test sends it explicitly; production emits it at the real outermost commit.)
- **Business scope fails closed**: a request with no active `setting_id` (or for another business) gets HTTP 403 from the guard, the route middleware, and the create/edit forms. The Livewire/HTTP tests that bypass `role.setting` now set the business explicitly.
- **Reference numbering**: `SaleReturn` references come from the locked `document_sequences` row (new `sale_return` document type, same `SLRN-YYYY-MM-NNNNN` format), reconciled against history first, so returns of different source sales can no longer read the same "latest + 1". An explicit reference (POS) is untouched.

Verification: POS gate 495 passed; standard classes 161 passed; `Modules/SalesReturn` 19 passed; `Modules/Pos/Tests/Feature` 328 failed (unchanged); `tests/Feature/Livewire` 56 failed (no new); sequence tests 8 failed, the same 8 as before this change (they need MySQL/multi-process).
