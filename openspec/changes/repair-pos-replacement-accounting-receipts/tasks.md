# Tasks

## 1. Replacement accounting evidence and payment allocations

- [x] 1.0 Normalize the existing pos-return-cross-owner-replacement main spec from its misplaced ADDED delta header into Purpose/Requirements sections without changing its calendar-date requirement or scenario; verify strict validation no longer reports that archive would refuse this delta.
- [x] 1.1 Add a reusable synthetic transaction-10717 fixture with printer Rp2,225,000, paper Rp45,000, unrelated Logitech Rp330,000, split owners, and serial replacement; verify a focused regression reproduces the current Rp4,825,000 result before the fix.
- [x] 1.2 Add module migrations/models for owner payment transfers and repair audits with unique event lineage, payment provenance, method, actor, and before/after evidence; verify fresh SQLite migrations and database uniqueness/amount-bound tests.
- [x] 1.3 Implement minor-unit commercial and payment transfer planning from pre-execution persisted evidence, including deterministic partial-paid/method allocation and blocked unsupported cases; verify fully paid, unpaid, partial-paid, discount/tax allocation, and rounding tests.
- [x] 1.4 Integrate incoming/outgoing transfers with shared effective-paid computation and both eager-loaded/query settlement paths while retaining invalidation/credit behavior; verify equal projected balances and original payment facts remain unchanged.
- [x] 1.5 Update collection reports, owner balance consumers, and receipt payment-method evidence to distinguish allocations from fresh collections; verify original cash/method totals count once and document the allocation semantics alongside the service.

## 2. Conserved replacement lifecycle

- [x] 2.1 Route cross-owner effects through the transfer service, correcting source header once and deriving replacement charge/paid/debt from evidence under existing transaction locks; verify the lifecycle-driven 10717 fixture finishes at Rp2,600,000 with source Rp45,000, replacement Rp2,225,000, and unrelated Rp330,000.
- [x] 2.2 Handle partial and chronological repeated replacements from the current owner with unique execution keys; verify retries create no duplicate sale/payment/transfer/physical effects and chains conserve total, paid, debt, and serial lineage.
- [x] 2.3 Reconcile mixed cash-return and replacement execution from one pre-execution state; verify cash reductions occur once, later cash returns retain debt-first behavior, and same-owner/note-only/informational component behavior stays intact.
- [x] 2.4 Add failure injection after source correction and after replacement creation; verify atomic rollback covers accounting, audit, stock, serials, and cost effects, and update lifecycle documentation with conservation checks.

## 3. Consistent read-only receipts

- [x] 3.1 Add proven duplicate-replacement detection before receipt header allocation using shared accounting evidence; verify the stale 10717 fixture refuses with historical access instead of inflating paper and that printing makes no financial writes.
- [x] 3.2 Extend POSReceiptReturnProjectionTest and receipt-route integration coverage for the corrected fixture, all ordinary print/reprint paths, and correct PRINT/REPRINT audits; verify Rp2,600,000, paper Rp45,000, effective serial XE4Q318288, and original-to-replacement history.
- [x] 3.3 Cover legitimate later sale price/header edits, packed units, bundle components, repeated replacements, and later cash returns; verify current values remain authoritative and historical/draft/loaded receipt behavior is preserved, and document refusal versus historical access.

## 4. Historical replacement repair

- [x] 4.1 Add a dedicated replacement repair planner with chronological evidence, repairable/unchanged/blocked classification, per-owner and method before/after totals, and stable fingerprints; verify synthetic stale and corrected 10717 fixtures plus missing provenance, conflicting edits, and active-return blocking cases.
- [x] 4.2 Register pos:repair-replacement-accounting with required business scope, bounded read-only discovery, explicit transaction previews, and invalid selection/mode rejection; verify command tests prove preview/discovery create no financial or audit writes.
- [x] 4.3 Implement explicit apply requiring actor, reason, reviewed fingerprint, group locks, revalidation, synthetic-payment invalidation only when proven, allocation transfer, audit, and normal projection verification; verify stale plans, rollback, cross-business linkage validation, duplicate selection, and repeat application tests.
- [x] 4.4 Verify repair preserves original customer collections, row charges/quantities, selling prices, return values, snapshots, stock, cost, and serial history; verify unchanged reruns add no corrective audit and existing cash-only balance repair tests still pass.
- [x] 4.5 Write an operator runbook with discovery/preview commands, fingerprint review, explicit apply examples, blocking conditions, and forward-correction rollback guidance; verify examples against isolated fixtures and make clear no historical apply occurs without separate review.

## 5. Integration and review handoff

- [x] 5.1 Run focused cross-owner lifecycle, receipt, settlement, collection/report, replacement repair, and existing cash balance repair suites on an isolated test database; verify all pass and record commands/results, using composer test:fresh-sqlite for the broader required migration/test check.
- [x] 5.2 Verify MySQL locking, unique transfer lineage, and concurrent retry behavior on a disposable database; record evidence that conflicting execution cannot double-transfer value or partially commit.
- [x] 5.3 Produce read-only discovery and detailed preview for real transaction 10717 in its owning business after implementation; deliver before/after evidence and any blocking reasons for review, without applying historical repairs.

## 6. Review remediation

- [x] 6.1 Trim only active-backed transfers on refund; reduce the full predecessor chain and only the specific root backing payment; record append-only reversal evidence (`pos_owner_payment_transfer_reversals`) before any reduction or removal; verify chain (A Rp45,000 / B Rp0 / C Rp1,725,000), sibling-payment (A Rp45,000 / B Rp1,725,000 / D Rp100,000), mixed, and full-refund regressions in `PosOwnerPaymentTransferRefundChainTest`.
- [x] 6.2 Fix historical chain replay (eager-loaded outgoing backing payments, chain-aware provenance for intermediate owners, post-replay header validation); verify full and Rp2,225,000 to Rp1,112,500 partial chains preview and apply in `PosReplacementAccountingChainRepairTest`.
- [x] 6.3 Harden locking after MySQL verification: `reduceTransferChain()` locks the backing payment before the transfer; `PosOwnerPaymentTransfer` saving hook uses locking reads for payment, predecessor, and allocation sums; `PosReplacementAccountingRepairService::apply()` runs with 3 transaction attempts (fingerprint revalidated each attempt).

### Verification record (base: `main` @ 6573f9d9 plus uncommitted change; note the session began on `feat/sales-return`)

Each command is `bash scripts/test-with-fresh-sqlite.sh <path>` (same as `composer test:fresh-sqlite -- <path>`), one at a time on a fresh SQLite database. Final run after all code changes:

| Suite (under `Modules/Pos/Tests/Feature` unless noted) | Result |
|---|---|
| POSReturnCrossOwnerReplacementTest | 16 passed |
| POSReturnReplacement10717RegressionTest | 9 passed |
| PosReplacementAccountingTransferServiceTest | 6 passed |
| PosOwnerPaymentTransferAndRepairAuditModelTest | 4 passed |
| PosReplacementAccountingRepairServiceTest | 6 passed |
| PosReplacementAccountingChainRepairTest | 2 passed |
| PosOwnerPaymentTransferRefundChainTest (incl. chain, sibling, invalidated-payment, mixed, full refund, reversal evidence) | 7 passed |
| POSReceiptReturnProjectionTest | 26 passed |
| POSReturnApprovalWorkflowTest | 15 passed |
| POSReturnReplacementDispatchWorkflowTest | 7 passed |
| `Modules/Pos/Tests/Unit/PosSettlementProjectionServiceTest.php` | 12 passed |
| `Modules/Sale/Tests/Unit/SaleLiveBalanceProjectionTest.php` | 7 passed |
| POSReturnAtomicLifecycleTest | 12 failed, 12 passed (baseline) |
| `tests/Feature/Reports` | 5 failed, 86 passed (baseline) |

Probes in `.scratch/pos-replacement-review-probes` (`vendor/bin/phpunit -c phpunit.xml <file>`): refund probes 13 passed, full chain 1 passed, partial chain 1 passed.

**Remaining failures, all pre-existing:** POSReturnAtomicLifecycleTest (12; "Nilai moneter retur kas telah berubah" guard at `PosReturnLifecycleService.php:1016`) and the five date-override report tests fail with identical counts and names on a clean worktree of `main`. The 11 Sale failures (`GlobalSaleAdjustmentTest`, `GlobalSaleDetailInlineEditorTest`; missing `sales.due-date.override`, invoice button, tax 99.1 vs 110.0) are tracked separately per `.scratch/sale-baseline-review/README.md`. Track these outside this change.

**MySQL concurrency (task 5.2 re-verified, disposable `mysql:8.0` container on port 3399, harness in `.scratch/mysql-concurrency-verification`):** two concurrent refunds on one chain applied serially (400/400, sibling payment untouched, 4 reversal rows); concurrent refund vs repair lock order produced deadlocks (1213) until the repair used retry, then 0 errors in 8 rounds (retry pattern emulated with a lock sequence, not the full apply); concurrent identical execution keys created exactly 1 row; concurrent 2 x 600.00 allocations from a 1000.00 payment allocated exactly 600.00 in 5 of 5 rounds after the locking-read hardening (an earlier run allowed over-allocation when run outside a transaction, which production paths never do). Not verified: full `apply()` under real concurrent load, and tests cannot run on MySQL (`tests/CreatesApplication.php` aborts for non-sqlite).

**Transaction 10717 (business 2):** preview fingerprint `8889a0a8612e2f661e2d6ca312f4f8bab050fcb33443bd9d0d1faab7f9eeae57` (repairable; Rp4,825,000 to Rp2,600,000). The operator then applied it: audit #1 APPLIED (actor 1), transfer #1 Rp2,225,000 from sale 214729 to 214745 backed by payment 208237, synthetic payment 208251 invalidated, original payment 208237 untouched. Post-apply preview is `unchanged` (fingerprint `a0b8013204caa388baef24fee3680536cb2b97e4c731e06318dcd401e9d9937c`), totals Rp2,600,000 paid, Rp0 debt, sales Rp45,000 / Rp2,225,000 / Rp330,000. Evidence in `.scratch/preview-10717/`.

## Workflow follow-up

- Transaction 10717 has been applied by the operator (see verification record); apply any further repairs only after separate explicit approval and matching evidence fingerprints; reconcile receipts, collections, owner balances, and historical facts afterward.
- Archive the change after implementation and review requirements are satisfied.
