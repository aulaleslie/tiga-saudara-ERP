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

## Group 4: Held custody and permission-controlled inspection

### What the counters mean

Held custody is a **separate physical balance**. It is never inside `ProductStock` or `Product::product_quantity`.

| Counter | Meaning |
|---|---|
| `quantity_received` | Units received for one physical occurrence (`sale_return_custodies` row). A tracked serial is its own row (quantity 1); untracked units share one row per return detail. |
| `quantity_released` | Units an authorized inspection moved into sellable stock. |
| `quantity_dispatched` | Units that left custody with the customer (original-goods dispatch, written by group 5). Always 0 until then. |
| held | `received - released - dispatched`, never negative (checked under row locks, plus a `CHECK` on MySQL 8.0.16+/MariaDB 10.2+). |

Warehouse physical quantity = sellable stock + held custody. Held units are shown on the return page (`Custody Barang Retur`) next to settlement, independently of the return's status, and stay visible after financial completion or source-sale archival.

Every change is an append-only row in `sale_return_custody_movements` (`RECEIVED`, `RELEASED`, `DISPATCHED`), with actor, time, notes and, for releases, the destination owner/location/tax. Movements cannot be updated or deleted. Quantities are whole units because the stock tables are integer based, so no truncation can happen.

### Flow

1. **Receive (first receiving only).** The return is stamped `custody_policy_version = 1`. Custody and a `RECEIVED` movement are created once per occurrence (`occurrence_key` is unique). No stock, `product_quantity` or good-stock `Transaction` is touched. A received serial becomes `RETURN_IN_PROCESS` with `is_in_return_process = true`, keeps its `dispatch_detail_id` (lineage), and gets a `SALE_RETURNED` history entry.
2. **Settle.** Cash refund / repair / cannot process are decided as before. **Settlement and completion never release goods.**
3. **Inspect and release** (`GET/POST /sale-returns/{id}/inspect`, permission `saleReturns.inspect`). The inspector picks serials or a quantity and **must write notes**. Release credits the bucket captured at receiving (location, tax or non-tax), updates `product_quantity` once, activates released serials (`ACTIVE`, dispatch link cleared), records an inspection (actor, time, notes, quantity) and `RELEASED` movements. Repeats of a submission (`request_key`) and exhausted custody are rejected without changes.

Release eligibility (`StandardReturnCustodyService::releaseBlocker`):

| Settlement of the unit | Release |
|---|---|
| none yet / not final | blocked |
| `CASH_REFUND`, approved | allowed |
| `REPAIR` dispatched with a **different** unit (replacement), original retained | allowed, but only by explicit inspection |
| `REPAIR` / `UNPROCESSED` awaiting or in dispatch, or same serial sent back | blocked (customer-bound) |

### Consumers of serials

Held serials are excluded by `available()`, `sellable()`, `availableBroken()` and `isSellable()`, by the replacement guard, purchase receiving validation, breakage policy and the adjustment store validation (dispatch link kept). Two real gaps were found and closed: stock-adjustment forms accepted any client-supplied serial id (UI and `AdjustmentController` create/update now refuse held serials), and a backstop in `ProductSerialNumber` (`HeldSerialGuard`) refuses Eloquent updates or deletes of a held serial from any code except the custody service. Raw query-builder bulk updates bypass model events by nature; no such path touches held serials today. Test: `HeldSerialConsumersTest`, plus the POS cart check in `POSSerialIncrementalAssignmentTest`.

### Permission assignment

`saleReturns.inspect` ("Inspeksi & Lepas Barang Retur") is defined in `app/Config/Permissions.php` and registered by migration `2026_11_08_000200_...`, which only **registers** it: no role (not even Admin) and no user receives it automatically. Grant it explicitly to the people who will release goods (role management UI). It is checked inside the service and the controller. **Assign it before accepting new standard receipts**, otherwise received goods stay held with nobody able to release them.

### Legacy receiving policy

- Records received **before** deployment keep `custody_policy_version = NULL`, have no custody rows and are never backfilled. Their stock was already counted. Inspecting them is refused with audit guidance; the audit command arrives in group 8.
- Records **not yet received** at deployment adopt the policy on their first receiving.
- `SALES_RETURN_CUSTODY_ENABLED=false` (`config/sales_return.php`) **blocks new standard receiving** (a clear message, return stays receivable); goods never become sellable on receipt. Existing custody, its display and inspection/release stay available. It is the switch for staged rollout and for an emergency stop; it never changes existing custody.
- POS receiving is untouched.

### Migration and rollback notes

- `2026_11_08_000100_create_sale_return_custody_tables`: adds `sale_returns.custody_policy_version` / `custody_policy_applied_at` (nullable) and creates `sale_return_inspections`, `sale_return_custodies`, `sale_return_custody_movements`. Additive: no existing row changes. It uses only portable schema builder calls; the held-balance `CHECK` is added only on MySQL/MariaDB, in a try/catch (older engines ignore CHECK, the locked service check remains authoritative). It ran on SQLite in the test suite; **it has not been run against MySQL/MariaDB**.
- `2026_11_08_000200_register_sale_return_inspect_permission`: creates the permission, gives it to Admin.
- **Rollback before any receipt under the policy:** set `SALES_RETURN_CUSTODY_ENABLED=false` (no schema change needed), or `migrate:rollback` both migrations. The custody `down()` now **refuses** (RuntimeException) if any custody, movement or inspection row exists, so the documented boundary is enforced, not just written.
- **Rollback after custody exists:** do **not** roll the schema back or deploy old receiving code. That would drop real held goods and, with old code, re-activate them. Set the flag to `false` to stop new custody, keep the tables and ledger, release held goods through inspection (or a separately reviewed reconciliation), and fix forward.

### Outstanding verification (explicitly not done)

The custody checks that need real row locking are **not proven by the SQLite suite**: concurrent inspections of the same custody row, concurrent release vs. receiving replay, and the MySQL `CHECK`/unique behaviour. The sequential tests prove idempotency and conservation, not contention safety. These stay open as tasks **6.6** and **9.2** (isolated MySQL/MariaDB concurrency run), and the migration still needs one dry run on a MySQL/MariaDB copy.

### Group 4 verification

| Check | Result |
|---|---|
| POS gate (same command) | **495 passed, 0 failed** |
| Standard + custody classes | **190 passed, 0 failed** (adds `StandardCustodyTest` 23, `HeldSerialConsumersTest` 7) |
| `Modules/SalesReturn` | 19 passed |
| Product / Purchase / PurchasesReturn / Sale / Adjustment suites | failing sets identical to the untouched baseline (75 / 33 / 42 / 18 / 4 + 1) |
| `tests/Feature/Livewire` | 56 failed, none new |
| `Modules/Pos/Tests/Feature` | 329 failed vs 328: the extra one is the new held-serial POS test, which fails only in the full-directory run like its five siblings in that class (all fail on the baseline too) and passes alone and with its class (11/11) |
| Sequence tests | 8 failed, the same 8 as the baseline |

### Group 4 review follow-up (4 gaps closed)

| Gap | Fix |
|---|---|
| Release truncated stock to integers (10.5 + 1 = 11) | Release math, `product_quantity`, buckets and `Transaction` evidence keep `decimal(15,3)`; custody/movement/inspection quantity columns are now `decimal(15,3)`. Note: `SaleReturnDetail.quantity` is itself integer-cast, so returns cannot yet *produce* fractions; the fix protects the existing fractional balances the release is added to (regression: 10.5 + 1 + 1 = 12.5). |
| Stale serial model bypassed the guard | `HeldSerialGuard::assertMutable` no longer trusts the instance's loaded state: it reads authoritative custody (FOR UPDATE inside a transaction) for any guarded dirty column; `product_id` is now guarded. |
| Disabling the flag made receipts sellable | The legacy stock branch is removed. Flag off = new receiving is blocked; existing custody and inspection stay usable. |
| Permission auto-granted to Admin | Migration registers only; nothing is granted. |
| Unconditional `down()` | Refuses when custody, movements or inspections exist. |

**Carried into group 5 (explicit):** `releaseBlocker()` decides "a different replacement unit was dispatched" by comparing serial strings. For **non-serial** goods there is no such string, so replacement-based release eligibility must be re-derived from the dispatch/custody ledger (quantity dispatched vs. retained) when dispatch becomes custody-based. Until then non-serial repair-with-replacement custody is never releasable (fail-closed).

Still outstanding: MySQL/MariaDB row-lock concurrency (the new `lockForUpdate` in the guard is likewise unproven on SQLite) and a MySQL migration dry run.

## Group 5: customer dispatch from / alongside custody

Applies only to returns **received under custody** (`custody_policy_version` set). Legacy returns keep the old dispatch behaviour untouched.

### Persisted dispatch identity
Migration `2026_11_09_000100` adds nullable `sale_return_item_settlements.dispatch_kind` (`ORIGINAL` | `REPLACEMENT`) and `dispatch_serial_number_id`. They are decided and validated at **request** time, re-validated under locks at **approval**, cleared on **rejection**. Release eligibility reads `dispatch_kind`, never serial strings, so serial and non-serial goods are treated alike. Non-serial requests choose the kind explicitly (`dispatch_mode`, default `ORIGINAL`, the safe custody-bound choice).

### Movement examples (return of 1 unit, location L, non-tax)
| Case | Custody (A) | Sellable stock at L | Transaction | Serial |
|---|---|---|---|---|
| ORIGINAL (repair / cannot process) | `dispatched` +1, held 0, `DISPATCHED` movement | unchanged | none (no phantom stock) | A: `RETURN_IN_PROCESS` -> `SOLD` (history entry) |
| REPLACEMENT (serial B, non-serial qty) | unchanged, still held | B's / the original's tax bucket **-1**, `product_quantity` -1 | `DISPATCH_RETURN` -1 | B -> `SOLD`; A stays held |

After a REPLACEMENT is dispatched the original custody becomes releasable by inspection (`saleReturns.inspect`), moving it into the bucket captured at receiving.

### Validation (request and approval)
Replacement serial: same product, `ACTIVE`, not broken / in return process / held, not the original, in a location owned by the return's business, with stock in its bucket. Non-serial replacement: location owned by the same business, required quantity available in the **original's tax bucket**. Any failure aborts the whole approval transaction (status, custody, stock, serial, transaction all roll back).

### Conflicts and retries
Release and dispatch both consume the same custody row under `lockForUpdate` with a `version` check and `held >= qty`; a pending ORIGINAL dispatch blocks release (customer-bound), a completed one exhausts custody, and a custody consumed elsewhere makes approval refuse without changes. Replayed approvals find the row no longer `DISPATCH_REQUESTED`; movements are unique per `disp:{settlement}:{custody}`. Rejection returns the row to `APPROVED_AWAITING_DISPATCH` with identity cleared and custody untouched.

### Unchanged
POS note-only replacements and POS owner/tax bucket behaviour (POS gate 495 passed).

Rollback: the migration's `down()` refuses once any settlement has a recorded `dispatch_kind`. Still outstanding: MySQL/MariaDB row-lock contention for release-vs-dispatch and the migration dry run on MySQL.

### Group 5 review follow-up (3 blockers closed)

| Blocker | Fix |
|---|---|
| Cannot-process accepted a REPLACEMENT | `UNPROCESSED` is ORIGINAL-only: enforced at request **and** approval (a forged persisted `REPLACEMENT` is refused), and `releaseBlocker()` now exempts only a `REPAIR` + `REPLACEMENT` + `DISPATCHED`, so the invalid combination can never release the original. |
| Replacement ignored active claims | A replacement serial must satisfy the model's own `isSellable()` (status, dispatch link, broken, return process, **active transfer custody**), must not be held in custody, must pass `PosReturnReplacementGuard` (POS draft/pending/approved/awaiting-dispatch replacement claims), and must not be claimed by another return's pending dispatch. Re-run at request and at approval. |
| Dispatch and completion were two transactions | Dispatch effects, completion roll-up and source-sale archival now run in one transaction; an injected completion failure rolls everything back. Notifications stay `afterCommit`. |

Notes: the POS-claim test proves the wiring with a stubbed guard (the claim rules themselves are the POS guard's existing, separately tested behaviour). Original sales-tracking `return_date` is kept as historical evidence. Lock order for release vs dispatch (sale -> header -> settlement -> custody -> serial -> product/stock) is the same in both paths; contention proof still needs the MySQL tests (6.6 / 9.2).

## Group 6: standard financial adjustment ledger

Applies to **standard** cash returns (`CASH_REFUND`) only. POS financial correction and payment invalidation are untouched; repair/replacement and cannot-process never create an adjustment.

### Formula (under the source-sale lock)
1. **Value** of the returned unit(s) = the source line's `sub_total` (net of line discount, includes line tax) less its share of the header discount (`discount_amount x line / sum(lines)`), per unit; shipping is not refundable. The last unit(s) of a line take the rounding remainder (3 units of Rp100 -> 33.33 / 33.33 / 33.34). Cumulative committed returns of the same sale line can never exceed its net value. A single serial is bounded by its own unit charge (never the detail subtotal). Client prices/nominals never count; the form's nominal may only be *lower* than the derived value, and zero is rejected (it is never turned into a full refund). `getEffectiveNominal()` no longer falls back to the detail subtotal.
2. **Debt first**: `debt_reduction = min(value, outstanding)` with `outstanding = total - active payments - credits - prior debt reductions`; `cash_refund = value - debt_reduction`, which must fit `active payments - prior adjustment refunds - older return payouts`. An excess **blocks** (nothing truncated, nothing partially committed).
3. Rp1,000,000 sale, paid Rp400,000, return Rp800,000 -> debt Rp600,000 + refund Rp200,000; net obligation Rp200,000, net paid Rp200,000, due 0. A debt-only return stores an adjustment and **no** `sale_return_payments` row.

### Evidence and projections
- `sale_return_adjustments` (migration `2026_11_10_000100`): one immutable row per settlement (unique), with committed value, debt reduction, cash refund, source fingerprint, policy version, actor/time and the payout link (NULL when no cash). `sale_return_item_settlements` gains `reviewed_value` / `reviewed_fingerprint`, recorded when the line is submitted and re-derived at approval; any change to sale totals, lines, payments, credits, other returns or refunds in between makes approval **stale-block** (reject and resubmit for renewed review; no money effect).
- Sale projection: `total_amount` stays the gross source evidence. `live_due_amount` (accessor, SQL scopes and `canonicalLiveDueFormula`) = `total - payments - credits - SUM(debt_reduction)`; header `paid_amount` = payments + credits - cash refunds, `due_amount` = live due, status from it. Global collection, settlement and POS-free receivable screens already consume `live_due_amount`, so canceled debt cannot be collected and nothing is deducted twice. `Sale::net_total_amount` = total - committed values. The return header's `paid_amount` is the cash actually paid out; the show page adds a "Penyelesaian Keuangan" panel (settled value / debt reduction / cash).
- Reports built on `sale_return_payments` now see only real cash payouts; revenue reports still read the gross `total_amount` (unchanged, no new double count).

### Blocked allocation: what to fix
Financial approval (not receiving, not custody) is blocked with a message when: the return line has no `sale_detail_id`; the sale header is not explained by its lines (`sum(lines) - discount + shipping != total`); header `paid_amount` differs from active payments (reconcile payments first); an active payment is zero/negative; a POS-allocated payment's allocation differs from its amount; quantity or value would exceed the line (including earlier returns); the refund exceeds verified remaining payments; or the evidence changed since review. Recovery: correct the source data (payment reconciliation / sale correction), then reject and resubmit the settlement line. Historical evidence is never rewritten. Limit: general shared-payment attribution beyond the POS allocation check is not modelled (standard payments are stored per sale); anything that looks inconsistent blocks rather than guesses.

### Rollback
`down()` of the adjustment migration refuses once any adjustment exists. Once adjustments exist: disable new standard cash settlements and fix forward.

### Verification (SQLite and real MySQL 8.0)
See the group 6 results in the stop report. MySQL isolated checks live in `tests/Feature/SalesReturn/Concurrency/` (suite "MySQL Sales Return Settlement Concurrency" in `phpunit.mysql.xml`; real OS processes call the production controller/services):
same settlement approved by 4 processes commits once; two reviews racing one balance never over-refund; a payment racing a debt-cancelling return never both commit; two inspectors releasing the same custody conserve it. Run with the repo convention (`scripts/run-mysql-sequence-tests.sh <phpunit args>` against `tiga_saudara_mysql_test` on port 33066). Note: the script pins MySQL 8.0.44, which was not available offline; the checks ran on the local `mysql:8.0` image (8.0.46) with identical compose settings (tmpfs, REPEATABLE-READ, strict mode).

### Group 6 review follow-up (3 blockers closed)

| Blocker | Fix |
|---|---|
| Percentage-discount sales blocked | The resolver reads the **established** representation (`SaleNormalizer`): percentage mode = `discount_percentage > 0` with `discount_amount = 0`; fixed mode = `discount_amount`. The share per line is the normalizer's own largest-remainder proration (`prorateGlobalDiscount`, lines in id order), so the allocation is deterministic and identical to how the sale was totalled. The fingerprint includes `discount_percentage`. |
| Reports showed canceled debt | `SaleAdjustmentSql` (per-sale debt reduction / cash refund / committed value, optionally as-of a date by `committed_at`) is joined into: customer receivables and aged receivables (balance = total - payments to date - debt reduction committed by the date), the sale report and sales-order completion report (net total = total - committed value, net paid = payments - cash refunds, so balance/status match the sale), the balance sheet receivable (sales - returned value - (payments - refunds), completed returns only, same filter as the refund cash) and the operational ledger/trial balance (completed returns: debit `Retur Penjualan`, credit Piutang for the returned value, dated by commit time, in opening balances and period events). Cash flow and payment reports already read only real payouts. |
| Return header kept an unpaid balance | `StandardFinancialService::refreshReturnHeader()`: `paid_amount` = cash actually paid out; `due_amount` = return total less value settled (cash-return adjustments plus dispatched non-cash lines); `payment_status` Paid when nothing is left, Partial when some is. Called on cash approval and on dispatch approval. Debt-only returns end Paid with `paid_amount = 0`. |

Verification extras: the collection test now builds a real payment method, asserts the `allocations` validation error ("melebihi sisa tagihan"), that no payment row was written, and that exactly the reduced debt can then be collected once (status PAID). The 68 failures of `--filter='Report|Archive|Archival'` were compared against a clean checkout of the previous commit (see stop report).

### Group 6 second review follow-up (3 integration gaps closed)

| Gap | Fix |
|---|---|
| Committed effects waited for header completion | Operational reports (balance sheet, cash flow, ledger/trial balance) recognize **committed standard adjustments and their linked payouts by their own dates**, independent of the return header's status. `SaleAdjustmentSql::applyReportablePayouts()` = legacy payouts of COMPLETED returns **or** payouts linked (via `sale_return_item_settlement_id`) to a committed adjustment. Completing the header later changes nothing already reported (tested by flipping the header AWAITING SETTLEMENT -> COMPLETED and comparing snapshots). |
| Archived sources caused double reduction | The returned value is subtracted only for the **same sales that form the source total**: balance sheet = setting scope, status, date, unarchived; ledger = `FulfilledTransactionEligibility` (unarchived, eligible status). The ledger also books linked refund payouts only for eligible sources (it already books sale payments only for them); the balance sheet keeps all cash legs (it counts payments of archived sources too), so payments and refunds stay aligned within each report. |
| Discounted return kept phantom due | The return header total is on the list-price basis (quantity x unit price, before the sale's header discount). `refreshReturnHeader()` now measures settled cash value on that same basis (adjusted units x the return line's unit price), while the committed (net) value remains what money/debt moved. A Rp1,000 return discounted to Rp900 settles to due 0 / Paid with no header rewrite; test fixtures now build realistic list-price headers. |

Notes: sale-report display stays net (Total/Pembayaran/Sisa); a separate gross-value column is optional and not added.

## Group 7: completion and archival coverage

### Return completion (document)
`StandardReturnCoverage::returnCompletion()` replaces "every existing settlement item is final". The expected set is **every received detail quantity and serial occurrence** (one occurrence per claimed serial, one per non-serial detail). A return completes only when each occurrence has a **final** settlement: `APPROVED` (cash) or `DISPATCHED` (repair / replacement / cannot-process). Missing, draft, submitted, rejected, approved-awaiting-dispatch and dispatch-requested occurrences keep the return in *Awaiting Settlement*, however many other items are approved. Consequence: a multi-line return stays processable after the first approval (before, one approved item completed the whole document and blocked the rest). `SaleReturn::settlement_status` ("Settled"/"Pending") uses the same rule.

### Source-sale state and archival
- **Cash coverage per original dispatch line** (`calculateEffectiveStandardReturnCoverage`): only `APPROVED` `CASH_REFUND` settlements count - debt-only included - mapped to their dispatch line (serial = 1 unit, non-serial = detail quantity), summed across returns. Every line must reach its dispatched quantity on its own (an excess on one line cannot conceal another's shortage). A draft/submitted cash settlement anywhere on the sale is a *pending contributor* and blocks archival; settlements with no dispatch line are reported as ambiguous and never prove coverage. Repair / replacement / cannot-process, unreceived, rejected quantities never count.
- **Sale status**: `RETURNED_PARTIALLY` appears once final cash coverage exists (never downgraded, never set by receiving or by service returns); `RETURNED` + `archived_at/by` + audit note only when coverage is full and the completing return is `Completed`. Archival is idempotent (cumulative returns archive once).
- **Explicit spec changes to old behaviour**: receiving no longer moves the sale to RETURNED (old: received quantity >= dispatched); the standard branch no longer archives from POS-style zero active quantities.
- **Service returns** (repair/replacement, cannot process) complete their document without archiving the source sale or changing its amounts; custody stays visible after completion (held cash-return goods remain held until an authorized inspection).

### POS vs standard (unchanged for POS)
POS-corrected sales still use active-quantity status and archival (`posArchivalSatisfied`), linked POS return headers still complete when all linked returns are completed, and POS never needs settlement rows or inspection. Mixed histories still change nothing. Paired regressions: `WorkflowSyncBranchesTest` (POS completes from active quantities without settlements; the same received quantities without final settlements never complete/archive a standard return).

### Reports around completion
Committed adjustments and linked payouts are recognized by their own dates, so completing the header (or dispatching the remaining repair item) leaves earlier report snapshots unchanged; `MultiLineCompletionTest` asserts this end to end (cash lines, pending repair, final dispatch).

### Verification
See the group 7 results in the stop report. MySQL contention and migration checks from groups 4-6 still apply; this group adds no schema.

### Group 7 review follow-up (4 coverage gaps closed)

| Gap | Fix |
|---|---|
| Invalid parents counted | `StandardReturnCoverage::isEffective()` - a return contributes only when **approved** and **physically received** (`status` awaiting settlement/completed **and** `received_at`). Rejected or unreceived parents contribute neither final settlements nor pending contributors, for both return completion and sale-level cash coverage. |
| Unreliable occurrences | Per detail: quantity must equal the serial cardinality, no duplicate serial ids, a serial product must carry its serials; otherwise the detail is ambiguous and blocks. Each occurrence needs **exactly one** valid final settlement and no competing open one (duplicate finals count nothing and are reported as ambiguous). A live settlement for an occurrence nobody received also blocks. |
| Finality ignored the method | `isFinal()`: cash-like methods (cash refund, customer credit, modify sale) are final only when `APPROVED`; service methods (repair/replacement, cannot process) only when `DISPATCHED`. `REPAIR`+`APPROVED`, `REPAIR`+awaiting dispatch and `CASH_REFUND`+`DISPATCHED` are not final. |
| Denominator included pending dispatches | Archival requires coverage of dispatch lines from **APPROVED** dispatches with **positive** dispatched quantity only, the same eligibility the return form uses; pending/rejected/zero lines are not part of the denominator. |

## Group 8: audit, legacy safeguards and rollout

### `sale-returns:audit` (read-only)
```bash
php artisan sale-returns:audit --setting=<business id>            # human readable
php artisan sale-returns:audit --setting=1 --sale=42 --json        # one source sale, machine readable
php artisan sale-returns:audit --setting=1 --return=7              # one return (plus its source sale checks)
```
- `--setting` is **required** (the audit is always one business). There is **no apply / fix / repair option** and none can be added by flag: the command only reads, takes no locks and writes nothing. Archived sales and returns are included. Use it on a production database freely; it is also safe to run repeatedly.
- Every finding carries `classification`, `record_policy` (`new-policy` received under custody, `legacy` received before it, `n/a`), the return/sale, a message and structured `evidence`.
  - **proven**: the recorded evidence contradicts itself or a hard rule (e.g. `RECEIVED_WITHOUT_RECEIVED_AT`, `NEW_POLICY_WITHOUT_CUSTODY`, `NEW_POLICY_RECEIVING_CREDITED_STOCK`, `DUPLICATE_FINAL_SETTLEMENTS`, `SERIAL_CARDINALITY_MISMATCH`, `UNMATCHED_SETTLEMENT_OCCURRENCE`, `METHOD_STATUS_MISMATCH`, `CASH_APPROVED_WITHOUT_ADJUSTMENT` on a new-policy return, `PAYOUT_EVIDENCE_CONTRADICTION`, `ADJUSTMENT_ARITHMETIC_CONTRADICTION`, `DUPLICATE_PAYOUT_FOR_SETTLEMENT`, `PAYOUTS_EXCEED_RETURN_TOTAL`, `REFUNDS_EXCEED_PAYMENTS`, `RELEASE_STOCK_MISMATCH`, custody/serial contradictions, `PREMATURE_COMPLETION` / `PREMATURE_ARCHIVAL` for new-policy returns, `MIXED_WORKFLOW_HISTORY`).
  - **suspected**: a problem is indicated but older rules or missing history could explain it (`LEGACY_RECEIVED_WITHOUT_STOCK_TRANSACTION`, `POSSIBLE_DUPLICATE_PAYOUT`, `SERIAL_HELD_WITHOUT_CUSTODY`, premature completion/archival of legacy records).
  - **ambiguous**: the evidence cannot decide (legacy received returns without custody, legacy approvals without an adjustment ledger).
- It never concludes a historical stock correction from current balances: stock findings cite recorded transactions and custody rows only. Corrective repair is a separate, reviewed change; the audit is the evidence for it.

Example (synthetic fixture):
```
Sales Return audit - business 1 (read-only; no data was changed)
Returns audited: 2   proven: 1   suspected: 4   ambiguous: 2

PROVEN (1)
  [RECEIVED_WITHOUT_RECEIVED_AT] return #1 SLRN-2026-11-00001 (legacy): Status says the goods were received but there is no received_at: the receiving evidence is missing.
      evidence: {"status":"AWAITING SETTLEMENT"}

SUSPECTED (4)
  [LEGACY_RECEIVED_WITHOUT_STOCK_TRANSACTION] return #1 SLRN-2026-11-00001 (legacy): A legacy received return has no recorded receiving stock transaction (it may predate transaction logging). Current stock is deliberately not used to decide.
  [LEGACY_RECEIVED_WITHOUT_STOCK_TRANSACTION] return #2 SLRN-2026-11-00002 (legacy): A legacy received return has no recorded receiving stock transaction (it may predate transaction logging). Current stock is deliberately not used to decide.
  [PREMATURE_COMPLETION] return #2 SLRN-2026-11-00002 (legacy): Completed although not every received occurrence has exactly one valid final settlement under the current rules.
      evidence: {"missing":["detail 2 serial 2"]}
  [PREMATURE_ARCHIVAL] sale #1 SL-6AEEC29EB1882 (n/a): The sale is archived/returned but final cash-return coverage of its original dispatch lines is not full. It was archived under earlier rules, so this may have been valid then.
      evidence: {"lines":{"1":{"dispatched":2,"returned":0,"covered":false}},"pending_contributors":0,"ambiguous_quantity":0}

AMBIGUOUS (2)
  [LEGACY_RECEIVED_WITHOUT_CUSTODY] return #1 SLRN-2026-11-00001 (legacy): Received before the custody policy: stock and serial handling at receiving cannot be reconstructed from custody. Custody-dependent actions are blocked; investigate manually.
  [LEGACY_RECEIVED_WITHOUT_CUSTODY] return #2 SLRN-2026-11-00002 (legacy): Received before the custody policy: stock and serial handling at receiving cannot be reconstructed from custody. Custody-dependent actions are blocked; investigate manually.
```

### Legacy safeguards
- Records completed or received before the policy are **never rewritten** (no backfill: policy markers stay NULL). Custody-dependent actions on a legacy received return (inspection release, custody display) are blocked with guidance to run the audit; settlement/dispatch of a completed return is refused (`assertCanSettle`). Only the **first receiving** of a return that was still unreceived at deployment adopts the policy (stamps `custody_policy_version`, creates custody for that return only).
- Tests: `LegacySafeguardsTest` (legacy blocks + no changes, adoption touches no other record, completed immutability) and `SaleReturnAuditTest` (zero-write: statement listener asserts no INSERT/UPDATE/DELETE/DDL and no `FOR UPDATE`, plus a byte-for-byte table snapshot, around the audit, the return/inspection pages, the financial preview, the coverage rules and a re-run of the additive migrations on populated data).

### Rollout checklist
1. Back up (`php artisan db:backup` / your normal backup). Deploy code; run migrations (additive; permission `saleReturns.inspect` is registered but **granted to no one**).
2. Assign `saleReturns.inspect` explicitly to the people who will inspect/release goods (role management). Do this **before** enabling receiving; otherwise held goods have nobody who can release them.
3. Keep `SALES_RETURN_CUSTODY_ENABLED=true` (default). `false` blocks NEW standard receiving only; existing custody and inspection keep working.
4. Run `sale-returns:audit --setting=<id>` per business and triage proven findings first; legacy ambiguous findings need manual investigation, not automated repair.
5. Validate with isolated synthetic fixtures (the test suites above; MySQL checks via `scripts/run-mysql-sequence-tests.sh`). **Never** use production transactions as mutating validation fixtures.

### Rollback after new-policy activity
Before any receipt under the policy: set the flag false or roll the migrations back. Once custody, adjustments or dispatch identity exist the down-migrations refuse (by design): disable new standard mutations (flag off, no inspection grants), keep the evidence, do not deploy old receiving code (it would make held goods sellable) and fix forward under a separately reviewed change using the audit output.

### Main spec normalization
`openspec/specs/sale-return-completion-archival/spec.md` was restructured to `# ... Specification` / `## Purpose` / `## Requirements` with its three requirement blocks byte-for-byte unchanged (no behavioural deltas applied early). Before: strict change validation reported the archive blocker (the main spec started with the delta header `## ADDED Requirements`, hiding all requirements); after: `openspec validate sale-return-completion-archival --type spec --strict` is valid and `openspec list --specs` shows 3 requirements.

### Group 8 review follow-up (audit hardening)
- **Business-scoped, exact stock evidence**: receiving transactions must match the return's business (`setting_id`), the return's products and the exact reason (`PENERIMAAN RETUR PENJUALAN: <reference>`, upper-cased) - no suffix/wildcard matching, so another business's or a lookalike document's transaction is never evidence. Release transactions are matched exactly against the custody owner's business(es), because a release credits the owner, which can differ from the return's business.
- **Refund grading uses historical payment authority** (standard sales only; POS/mixed sales are skipped): refunds above everything *ever* paid are **proven** (`REFUNDS_EXCEED_PAYMENTS`); refunds above only the payments active *today* are **ambiguous** (`REFUNDS_EXCEED_ACTIVE_PAYMENTS`), because a later legitimate payment invalidation can explain them.
- **Aggregate claims**: per original dispatch line, the quantity claimed across all non-rejected returns must not exceed the quantity of an approved dispatch (`EXCESS_CLAIM_ON_DISPATCH_LINE`), and a serial claimed by more than one non-rejected return is flagged (`SERIAL_CLAIMED_BY_SEVERAL_RETURNS`). Both are *proven* and only evaluated for standard sales (POS-mutated histories use their own quantity model; mixed histories are reported as `MIXED_WORKFLOW_HISTORY`).
- **Filters are validated, never widened**: `--setting`, `--sale`, `--return` must each be a positive integer, and a supplied sale/return must exist in the audited business; anything else exits with an error and prints no report (`--sale=abc` no longer silently audits everything).
