## 1. Repair Known Test Defects

- [x] 1.1 Seed the `sales.reporting-date.override` web permission record, without granting it, in `SaleShowSerialBadgeTest` so all three scenarios reach serial-lineage rendering assertions.
- [x] 1.2 Replace cross-owner replacement date object-identity comparison with normalized persisted calendar-value comparison and add a differing execution-date case.
- [x] 1.3 Run only `SaleShowSerialBadgeTest` and the focused cross-owner replacement date test, confirming the previously reproduced failures are removed for their intended reasons.

## 2. Define Effective Standard Return Coverage

- [x] 2.1 Extract a lock-safe lifecycle query that groups source dispatch quantities and effective received return quantities by dispatch identity and excludes unreceived or rejected return details.
- [x] 2.2 Define full coverage only when every source dispatch line is fully covered; report ambiguous legacy details without allowing them to prove full coverage.
- [x] 2.3 Add focused service tests for one-line full coverage, partial coverage, multi-line uneven coverage, cumulative multiple returns, rejected/unreceived exclusion, and ambiguous legacy lineage.

## 3. Complete and Archive Standard Sales Returns Safely

- [x] 3.1 Extend `SaleReturnLifecycleSyncService` so completed full standard returns archive the source Sale when either POS-corrected active quantities are zero or effective standard-return coverage is complete.
- [x] 3.2 Preserve `RETURNED` status, `archived_at`, `archived_by`, and idempotent Sales Return reference notes without mutating Sale details or dispatch quantities in the standard path.
- [x] 3.3 Prove settlement completion does not change already-restored ProductStock, Product quantity, serial state, or return transaction count and does not duplicate refund payment on retry.
- [x] 3.4 Add focused full, partial, cumulative, and retry feature tests across standard Sales Return settlement and the existing POS full-return archival path.

## 4. Build the Bundle Release Gate

- [x] 4.1 Document a focused release manifest containing the exact bundle definition/lifecycle, pricing, Normal Sales, POS split, dispatch, serial, HPP/report, return/replacement, and idempotency test files.
- [x] 4.2 Add focused SQLite migration verification for the bundle/HPP/return schema changes, including additive up behavior and safe rollback of only the touched migrations.
- [x] 4.3 Run the release manifest and classify every failure; leave no implementation defect or unexplained failure in the gate.
- [x] 4.4 Record the controlled-rollout prerequisites and release result without requiring unrelated full module or application suites.

## 5. Final OpenSpec Verification

- [x] 5.1 Strictly validate this change and confirm all focused regression evidence is linked from its implementation handoff.
- [x] 5.2 Confirm the archived or wrap-ready `harden-product-bundle-hpp` change remains independently valid and that production enablement waits for this release-safety gate.
