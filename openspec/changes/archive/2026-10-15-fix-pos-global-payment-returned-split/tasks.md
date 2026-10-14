# Tasks

## 1. Shared child-Sale eligibility and projection

- [x] 1.1 Add a reusable POS child-Sale eligibility decision that accepts ordinary eligible Sales or fully returned Sales with zero total and zero canonical live due regardless of archival; verify focused cases for archived/unarchived returned-zero, residual total/debt, unrelated archived/rejected zero-value, and ordinary eligible Sales.
- [x] 1.2 Use the shared decision in `PosSettlementProjectionService` while preserving all reachable Sales, mapping diagnostics, canonical financial aggregation, and eager-loading; verify `PosSettlementProjectionServiceTest` regressions for a returned-zero split plus a payable sibling, all-zero splits, and broken mapping.

## 2. Preview and atomic submission

- [x] 2.1 Replace duplicated child-Sale eligibility restrictions in `GlobalPosMultiPaymentService` preview with the shared decision and retain existing owner-priority planning; verify focused preview cases allocate only to outstanding eligible siblings and agree with projection for archived/unarchived returned-zero Sales.
- [x] 2.2 Apply the shared decision to fresh locked submission data while retaining locks for every reachable Sale, canonical balances, authorization, and atomic audit writes; verify successful payment creates no payment or positive audit allocation for the exempt Sale, and stale eligibility/overpayment rejects the complete batch without partial writes.

## 3. Focused integration verification

- [x] 3.1 Confirm controller and Livewire register actions, same-customer candidates, and form access consume the revised projection consistently; adjust consumers only if needed and verify focused route/Livewire regressions for payable siblings with an archived returned-zero split, all-zero exclusion, and unchanged create permission enforcement.
- [x] 3.2 Add a focused regression representing committed return quantity/financial corrections while the return header is not completed; verify form, preview, and submission allow sibling collection without requiring archive or changing return lifecycle state.
- [x] 3.3 Run only the affected projection, multi-payment service, and relevant controller/Livewire test classes or filters with the repository's SQLite-compatible setup (for example `php artisan test --filter=PosSettlementProjectionServiceTest` and `php artisan test --filter=GlobalPosMultiPaymentServiceTest`), plus applicable syntax/style checks on changed PHP; record exact commands and results. Do not run or require the full application test suite.
