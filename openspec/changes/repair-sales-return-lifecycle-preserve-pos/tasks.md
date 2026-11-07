# Tasks

## 1. Stabilize the existing POS Return baseline

- [x] 1.1 Preserve replacement commercial amounts in `PosReturnApprovalPreviewPlannerService` by limiting cash valuation overrides to cash resolutions; verify bundled Rp1,000 and ordinary Rp1,500 preview regressions, generated cross-owner amounts, and zero-refund note-only semantics.
- [x] 1.2 Use normalized validated line intent for valuation in `PosReturnSubmissionService::store` and `update`; verify header-default omission yields the correct cash amount and explicit none remains none in create/edit tests, including the audit-total and receiving-settlement reproductions.
- [x] 1.3 Remove the undefined `$cumQty`/swallowed-error valuation fallback ambiguity; verify targeted tests exercise missing-selection/fallback paths and reject unreliable valuation without persisting a silently substituted amount.
- [x] 1.4 Correct the two planner component fixtures to match original total component composition and serialized quantity normalization; verify quantities 1 versus 2 using the captured per-bundle ratio, including partial whole-bundle returns.
- [x] 1.5 Update serial-claim and missing-component error assertions to the actionable rejection contract while retaining no-save/no-stock-change assertions; verify the isolated lineage and bundle regression cases pass.
- [x] 1.6 Repair monetary preconditions and bundle/HPP fixtures in `POSReturnAtomicLifecycleTest`, `POSReturnBundleLifecycleExecutionTest`, and `POSReturnCostReversalTest`; verify all nineteen previously blocked cases reach their intended stock, HPP, payment, or injected rollback assertions without disabling stale-value guards.
- [x] 1.7 Replace the manual COMPLETED simulation in the successive-partial-return case of `PosRowTotalRoundingTest` with real approved execution; verify remaining entitlement, frozen increment, and source residual after each return.
- [x] 1.8 Fix any newly exposed return-domain defects from 1.1–1.7 with a reproducing regression; verify the relevant POS Return classes, rounding integration, receipt projection, and global payment return cases pass before starting group 2, and record exact commands/results and baseline classifications in a module repair guide.

## 2. Isolate standard and POS return ownership

- [x] 2.1 Implement a shared workflow resolver recognizing direct legacy checkout links, split checkout-sale links, and linked POS return records; verify ordinary, legacy POS, split-owner POS, and ambiguous mixed-history fixtures resolve correctly.
- [x] 2.2 Filter standard sale lookup and reject POS-origin standard create/edit submissions server-side; verify UI lookup and direct Livewire payloads cannot create duplicate standard claims against POS sales and provide POS navigation guidance.
- [x] 2.3 Protect all standard header, settlement, inspection, dispatch, and legacy payment mutation adapters against linked POS records; verify direct endpoint and Livewire calls are rejected even for a user holding both permission sets, while authorized reads still work.
- [x] 2.4 Separate standard status/coverage services from shared POS completion/archive branches; verify POS final approval completes linked headers without standard settlement rows or inspection and preserves legacy lifecycle tests.
- [x] 2.5 Block unsafe new entitlement, financial, and archive actions on historical mixed-flow sources without rewriting them; verify existing records remain readable and unchanged, and document the workflow ownership boundary and operator guidance.

## 3. Restore standard settlement surfaces and server guards

- [x] 3.1 Restore `SaleReturnDetail::selectableSettlementMethods()` with repair/replacement, cash return, and cannot process while retaining historical method labels; verify settlement and detail pages render and new direct legacy/unknown methods are rejected.
- [x] 3.2 Repair customer, location, dispatch, authentication, and other required fixtures in standard create/locking/serial/settlement tests with foreign keys enabled; verify the fourteen setup failures reach their behavioral assertions rather than disabling SQLite constraints.
- [x] 3.3 Introduce focused standard lifecycle mutation services with business/action authorization and source-sale-first locking; verify direct cross-business requests, unapproved or unreceived settlement, stale claims, forged row lineage, and excess quantity/serial claims fail without writes.
- [x] 3.4 Guard settlement submit/reset against persisted noneditable states and unify bulk/item dispatch prerequisites; verify approved rows cannot be resubmitted, reasons are required for cannot process, and an invalid bulk item rolls back the whole request.
- [x] 3.5 Add database uniqueness and locked revalidation for repeatable effects and move notifications after commit; verify receiving/settlement replay and injected failures do not duplicate effects or emit committed-action notifications on rollback.
- [x] 3.6 Document the standard state transitions and permitted actions in the repair guide; verify every documented transition has a corresponding focused feature test.

## 4. Add held custody and permission-controlled inspection

- [x] 4.1 Add module migrations/entities for versioned custody balances, append-only movements, and nullable receiving policy markers, with source, owner, location, tax, quantity, and optional serial anchors; verify SQLite and MySQL-compatible migration behavior, uniqueness, nonnegative balances, and no historical balance backfill.
- [x] 4.2 Change first standard receiving under the new policy to create held custody and unavailable serial state without good-stock increments; verify held + released + outbound conservation, actor/time/history, correct owner/location, and no changes to POS receiving buckets.
- [x] 4.3 Register `saleReturns.inspect` through existing permission conventions and add an inspection/release service and return UI requiring notes and selected quantity/serials; verify denied access, partial release, repeated release, archived-source access, and exhausted-custody rejection.
- [x] 4.4 Move inspected retained goods into the correct owner/tax stock bucket with aggregate/global counter reconciliation and serial activation; verify cash/refund completion alone never releases stock and replacement originals remain held until explicit inspection.
- [x] 4.5 Display held, released, and customer-dispatched quantities independently of settlement status; verify completed cash returns can still show actionable retained custody and reconcile warehouse physical quantities without presenting held goods as sellable.
- [x] 4.6 Protect sale, POS replacement, transfer, purchase serial reuse, and adjustment selectors/mutators from consuming held serials, including paths bypassing common scopes; verify targeted consumer regressions reject held goods while existing POS stock selection remains valid.
- [x] 4.7 Document custody counter meaning, inspection permission assignment, and legacy receiving policy in the repair guide; verify an ordinary receive/inspect/release walkthrough against a synthetic serial and non-serial return.

## 5. Reconcile standard repair and replacement dispatch

- [x] 5.1 Dispatch original repaired or cannot-process goods directly from their held custody with outbound audit and serial lineage; verify same-serial and non-serial original dispatch consume custody once without phantom warehouse stock or unrelated good-stock deduction.
- [x] 5.2 (carried from group 4 review: re-derive release eligibility for NON-serial replacement from the custody/dispatch ledger instead of comparing serial strings) Dispatch different-unit replacements from available owner/location stock while preserving original custody; verify serial A/B lineage, standard physical non-serial replacement, required bucket sufficiency, and rollback on invalid replacement identity or stock shortage.
- [x] 5.3 Prevent inspection release and customer dispatch from consuming the same held goods; verify conflicting/repeated actions cannot overdraw custody and rejected dispatch returns the item to its permitted awaiting-dispatch state.
- [x] 5.4 Document original versus replacement movement examples and verify POS non-serial note-only replacement and owner-bucket regressions remain unchanged.

## 6. Implement source-valued debt-first financial settlement

- [ ] 6.1 Add immutable, uniquely settlement-keyed financial adjustment evidence with committed value, debt reduction, actual refund, reviewed source fingerprint, policy version, actor/time and payout link; verify migration preservation and no duplicated evidence on retries.
- [ ] 6.2 Resolve standard attributable charge, discount/tax allocations, prior returns, active payments, shared-payment allocation, and prior refunds; verify single-serial caps, cumulative limits, stale monetary review, and actionable blocks for ambiguous evidence while receiving remains available.
- [ ] 6.3 Implement locked debt-first settlement and positive-only cash payout, removing zero-to-full fallback; verify the Rp1,000,000/Rp400,000/Rp800,000 example yields Rp600,000 debt reduction plus Rp200,000 refund, and debt-only/zero-charge completion creates no cash-payment row.
- [ ] 6.4 Apply the standard adjustment projection to source net header balances, payment status, return settled-versus-cash displays, affected financial/receivable projections, and global sale collection; verify conservation against original line evidence and no double deduction or collection of canceled debt.
- [ ] 6.5 Preserve source financial values for repair/replacement and cannot process; verify their completion and dispatch make no commercial adjustment and leave POS payment invalidation/rounding behavior intact.
- [ ] 6.6 Add concurrent payment/return approval and injected-failure regressions using the repository's isolated MySQL/MariaDB conventions; verify source locks prevent over-refund and partial debt/payout commits, and document evidence requirements and blocked-allocation recovery guidance.

## 7. Enforce complete settlement coverage and correct archival

- [ ] 7.1 Compute expected settlement coverage from every received detail quantity and serial occurrence; verify missing detail, missing serial, rejected/draft/submitted item, and awaiting-dispatch cases prevent completion despite other approved items.
- [ ] 7.2 Require final standard cash-return coverage per original dispatch line for source-sale RETURNED/archival, including debt-only returns; verify cumulative returns archive once, pending contributors block archival, and excess on one line cannot cover another shortage.
- [ ] 7.3 Preserve active source-sale state and money for service-return completion, retaining independently valid prior partial cash-return state; verify repair/replacement and cannot process complete their document without full-return archival and held custody stays visible after financial completion.
- [ ] 7.4 Add paired POS/standard completion regressions and update archival tests to the explicit spec changes; verify the POS active-quantity branch and linked-header completion retain their established semantics, and document the distinction in the repair guide.

## 8. Deliver historical audit and prospective deployment safeguards

- [ ] 8.1 Implement a business-scoped read-only `sale-returns:audit` command with source/return filters and text/JSON evidence; verify findings cover suspected phantom stock, duplicate payouts, incomplete settlement, excess claims, and mixed histories with proven/suspected/ambiguous classifications.
- [ ] 8.2 Keep existing completed records immutable and block custody-dependent mutations of already-received legacy records when evidence is insufficient; verify first receiving of an older unreceived standard return adopts new custody without backfilling other rows.
- [ ] 8.3 Prove audit, migration, preview, and display cause zero domain writes using before/after snapshots and query assertions; verify archived records are included safely and no apply/repair mode is exposed.
- [ ] 8.4 Document rollout, explicit inspection permission assignment, legacy action blocks, audit usage, and rollback after new-policy activity; verify documented commands against synthetic fixtures and confirm no production transaction is used for mutating validation.
- [ ] 8.5 Normalize the existing main `openspec/specs/sale-return-completion-archival/spec.md` into Purpose/Requirements structure while preserving its three existing requirement blocks verbatim; verify main-spec validation recognizes all requirements and strict change validation no longer reports the malformed-target archive blocker, without applying this change's behavioral deltas early.

## 9. Verify integrated compatibility

- [ ] 9.1 Run focused standard tests across module, root feature, and Livewire directories plus the stabilized POS Return, receipt, rounding, global payment, bundle, serial, owner-bucket, and HPP cases; verify all relevant tests pass and compare against phase 1's green baseline with no weakened assertions.
- [ ] 9.2 Run `composer test:fresh-sqlite` and focused MySQL/MariaDB concurrency verification; record exact results and separately identify unrelated baseline failures, with no unresolved failures in the return compatibility gate.
- [ ] 9.3 Verify end-to-end standard cash/debt-only/repair/replacement/cannot-process flows, partial inspection, retries, and direct POS bypass attempts using synthetic data; confirm custody conservation, financial reconciliation, source archival, and historical immutability together.

## Workflow follow-up

- Use [follow-up.md](follow-up.md) for the preserved decisions and post-implementation review, rollout preparation, read-only historical audit, and separately scoped remediation sequence.
- Review the completed change and archive through the explicit OpenSpec archive workflow when implementation and verification are complete.
