# Tasks

Verification is limited to focused new and directly affected tests. Do not plan or run the full suite or `composer test:fresh-sqlite`. Use synthetic fixtures; never mutate transaction 9218 or historical completed returns for verification.

## 1. Persist rounding context and implement cumulative valuation

- [ ] 1.1 Add nullable versioned source-sale rounding context storage and model casts, with no historical population; verify additive migration and legacy null-context loading in focused migration/model tests.
- [ ] 1.2 Implement authoritative commercial-line baseline resolution, historical active-return seeding, POS-business increment selection and first-commit policy freezing; verify older-sale fixtures, ambiguous-evidence blocking, business isolation and no writes from preview.
- [ ] 1.3 Implement cumulative minor-unit valuation, caps and exact final residual using the existing half-up calculator; add and run focused tests for Rp790000/120 at Rp100, fourth-unit Rp6500 delta, partition independence, zero increment, coarse-increment zero delta and legacy residuals.
- [ ] 1.4 Document persisted context fields and baseline/reversal rules alongside the valuation service; verify the documented examples against the focused calculation tests.

## 2. Integrate preview and atomic execution

- [ ] 2.1 Route draft estimates and approval previews through the shared valuation and distribute one commercial amount across owner/bundle fragments; verify focused preview tests for split-owner, bundle, tax and bill-discount reconciliation.
- [ ] 2.2 Persist allocation evidence and frozen context during final approval with deterministic source locking and stale-preview validation; verify focused setting-change, concurrent-state, retry and rollback tests.
- [ ] 2.3 Make reversal/cancellation allocation release follow existing lifecycle eligibility without recalculating historical refunds; verify a focused return/reversal/new-return sequence preserves quantity and money caps.

## 3. Preserve sale authority and reconcile settlement

- [ ] 3.1 Correct affected detail values by committed return amount and aggregate remaining headers without unit-price reconstruction; verify a synthetic 9218-shaped regression preserves an untouched Rp790000 marker line and leaves original checkout snapshots unchanged.
- [ ] 3.2 Preserve pre-tax/tax, discounts and retained charges while allocating the correction exactly once; verify focused taxable, discounted and split-owner/bundle correction cases and unchanged replacement-only commercials.
- [ ] 3.3 Use the committed amount for linked return evidence, cash refunds and debt reduction; verify focused unpaid, fully paid, mixed paid/debt and final-exhaustion cases agree across preview, source balance and settlement.

## 4. Focused integration verification

- [ ] 4.1 Run only the new valuation/return tests and directly affected existing POS return approval-preview, execution and rounded-amount regression filters with `php artisan test`; record exact commands and results in a change-local verification note.
- [ ] 4.2 Review migration and changed write paths for prospective behavior, absence of repair/backfill, and read-only previews; document evidence that transaction 9218 and completed historical records are not rewritten, without production mutations.
- [ ] 4.3 Run `openspec validate preserve-pos-return-rounding --strict` and review implementation against the three delta specs; record any remaining limitations in the verification note.
