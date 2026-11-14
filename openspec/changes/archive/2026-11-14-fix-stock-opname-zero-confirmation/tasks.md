# Tasks

## 1. Explicit physical-zero intent in counting drafts

- [x] 1.1 Extend the existing row payload and `CountDraftService` persistence with dedicated `zero_confirmed`, `zero_confirmed_by`, and `zero_confirmed_at` properties; derive attribution server-side and preserve unchanged attribution. Verify draft save/reopen, missing legacy properties, forged client attribution, and unrelated-header edits with focused draft-service tests.
- [x] 1.2 Add the physical-zero checkbox to the existing Livewire table for zero-total serialized and ordinary rows; clear confirmation/attribution through count, scan, serial add/remove/reclassify, product removal, and selected-location changes. Verify focused Livewire tests cover default zero, explicit confirmation, change-and-return-to-zero, save/reopen, and counters without system-stock visibility.
- [x] 1.3 Validate saved-versus-submitted count/scope changes server-side so stale confirmation is not silently carried into altered rows, while fresh explicit confirmation remains possible. Verify direct-request tests, unchanged saves, row removal/re-addition, and failed-validation form hydration.

## 2. Submission and approval safeguards

- [x] 2.1 Extend the existing stock-opname lifecycle to reject submission of positive current selected-pool stock with zero entered total and missing valid confirmation, while retaining incomplete draft saves. Verify focused lifecycle tests cover both product types, zero system stock, partial reductions, condition-only changes, and absence of notifications or state changes on rejection.
- [x] 2.2 Apply the same authoritative check under existing approval locks before posting; make old pending documents use reject/edit/resubmit without an approval-page confirmation shortcut. Verify positive stock appearing after submission, older pending documents, valid confirmed zero, approved-history rendering, repeated approval, and atomic no-effects failures.

## 3. Serialized unmatched-quantity reconciliation

- [x] 3.1 Extend existing serial reconciliation/planning to calculate per-selected-location and condition/tax-bucket stock coverage and unmatched effects; expose the same plan to preview and approval. Verify focused reconciliation tests for zero, mixed retained/unmatched quantity, new serials covering existing units, true surplus, independent good/bad, deterministic multi-location allocation, and protected/inconsistent coverage.
- [x] 3.2 Apply planned unmatched corrections and new-serial attribution through `StockOpnameApprovalService` using existing locks, ownership/tax rules, custody/reservation guards, transactions, histories, and notifications. Verify approval regressions for unmatched 1→0, unmatched 1 plus one new serial→1, unmatched 2 plus three new serials→3, and stock 2 with one retained serial→1.
- [x] 3.3 Extend multi-location/guard regressions to verify unselected stock remains unchanged, existing serial movement/omission behavior remains intact, protected custody and buyout/transfer reservations block unsafe reductions, contradictory coverage cannot create negative buckets, and failures after mutations roll back all effects. Verify preview totals equal applied totals and existing bounded-query/lock-order tests still pass.

## 4. Review and immutable audit evidence

- [x] 4.1 Extend existing stock-opname DTOs/view models, review Blade views, and immutable `approval_result` with explicitly named confirmation and unmatched-reconciliation evidence; keep original fields' meanings. Verify projection/view tests show confirmer/time, before/after and net effects, no stock-derived leakage to counters, and unchanged historical results after later stock movements.
- [x] 4.2 Add a concise manual verification checklist alongside this change documenting confirmation, pending-document correction, and both unmatched-stock cases. Verify the checklist follows existing create/save/submit/review/approve screens and requires no invented workflow or direct database repair.

## 5. Integration verification

- [x] 5.1 Run the focused Adjustment draft, Livewire, lifecycle, reconciliation, approval, view, and claim-guard tests using `php artisan test` with relevant paths/filters; record results and distinguish unrelated pre-existing failures. Use the isolated SQLite runner when a fresh database is needed; never target business data for automated tests.
  - Result (2026-11-14): `tests/Feature/Adjustment` 207 passed (incl. new `StockOpnameZeroConfirmationTest`, 18 tests); `--testsuite=Adjustment` 697 passed, 3 failed — `ForwardDispatchP…`, `TransferEntryInteractionCoordina…`, `TransferStockConditionSegmentedC…` fail identically on a clean checkout (pre-existing, unrelated).
- [x] 5.2 Run the manual checklist on a staging copy: Perdana unmatched one confirmed empty becomes zero while White Knight remains one; in a separate fixture, entering the physical serial for unmatched one leaves total one. Verify review matches applied history, unconfirmed zero cannot proceed, and no POS-return behavior is changed.
