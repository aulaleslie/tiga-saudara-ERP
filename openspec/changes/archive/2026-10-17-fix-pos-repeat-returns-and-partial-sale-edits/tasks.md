# Tasks

## 1. Establish repeat-return regression evidence

- [x] 1.1 Add the reported fixture (5 non-serial pcs plus serial A; complete A-to-B; request cash return of 1 non-serial pc) using existing POS return helpers; verify a focused approval test exposes the current failure and expects remaining quantity 4 with B unaffected.
- [x] 1.2 Add successive partial-return and independent pending-return cases to POSReturnQuantityGuardTest and POSReturnSnapshotStalenessTest; verify cases distinguish unrelated drift, genuine source corruption, exhausted quantity, and overlapping claims.

## 2. Validate affected return eligibility

- [x] 2.1 Replace transaction-wide snapshot drift blocking with affected-item source and financial validation across submission and approval planning, retaining original snapshots; verify the mixed-item regression and unrelated-item price edit pass while genuine source mismatch fails without mutation.
- [x] 2.2 Reconcile remaining entitlement with already-applied Sale quantity reductions and replacement execution provenance; verify successive cash returns reach zero exactly once and replacements preserve one returnable held unit.
- [x] 2.3 Revalidate claims under shared source locks during approval execution, excluding the current request; verify distinct pending items can execute independently and stale competing approvals cannot double-consume quantity or a serial occurrence.

## 3. Resolve repeated serialized occurrences

- [x] 3.1 Resolve completed, non-reversed replacement events by causal occurrence and checkout lineage rather than serial identity alone; verify A-to-B-to-A, a subsequent return of A, and a chain beyond the existing fixed hop bound resolve without a return-count cap, while repeated events or ambiguous evidence fail safely.
- [x] 3.2 Align lookup, submission, approval-plan claims and lifecycle guards with current held occurrences; verify multiple serialized units retain explicit independent pairs, old completed claims do not block new occurrences, and duplicate current claims remain blocked.
- [x] 3.3 Preserve existing replacement selection and locked execution eligibility plus returned good/bad disposition; verify eligible reused A is accepted and sold/reserved/ineligible replacements remain rejected without changing their condition.
- [x] 3.4 Update shared resolver consumers in current receipt projection where required; verify current serial A after A-to-B-to-A, complete ordered history, later cash return, and focused bundle/cross-owner cases affected by the resolver change.

## 4. Enable remaining-sale monetary edits and renewed review

- [x] 4.1 Enable RETURNED PARTIALLY monetary-only mode across Sale eligibility, ordinary/global entry points and Livewire UI/backend under existing permissions and setting rules; verify focused SaleMonetaryEditTest authorization and rendering cases and continued refusal for fully returned Sales.
- [x] 4.2 Normalize monetary edits against remaining quantities while preserving zero-quantity audit rows, completed refunds, settlement evidence and protected execution/cost facts; verify 5-to-4 quantity repricing, unchanged completed refund, reconciled paid/debt and rejection of protected-field tampering.
- [x] 4.3 Add explicit renewed review of affected pending-return monetary basis through the existing review/update surface, preserving original audit evidence and read-only preview; verify updated item price or relevant header allocation requires acceptance, an old approval confirmation fails, and unrelated edits do not block approval.

## 5. Focused integration verification

- [x] 5.1 Run only affected named test files or method filters with php artisan test, selecting from POSReturnSnapshotStalenessTest, POSReturnQuantityGuardTest, POSReturnApprovalPreviewPlannerTest, POSReturnApprovalPlanPersistenceTest, POSReturnApprovalWorkflowTest, POSReturnResoldSerialTest, SaleMonetaryEditTest and affected lineage/receipt cases; record exact commands and results and resolve affected failures. Do not run or require the full suite or composer test:fresh-sqlite.
- [x] 5.2 Verify the integrated mixed-item sequence, A-to-B-to-A, multi-serial pairing, partially returned monetary edit and renewed review using focused route/Livewire tests or staging walkthrough; record remaining quantities, effective serials, preserved refunds and actionable rejection messages.
