## Context

Transaction 3393 in the configured database is DRAFT with no completed checkout. Its business increment is 100.00. Four rows have raw totals 250000, 25992 (24 × 1083), 14000, and 28000; the saved header is 318000. The rows lack authoritative total metadata. This is evidence of a draft persistence/display discrepancy, not evidence of incorrect settled payments.

`PosTransactionService::saveAndNew` obtains totals from the calculated snapshot but passes raw cart lines to `PosTransactionSnapshotMapper::persistLines`. The mapper already supports `line_authoritative_net_minor`. The transaction detail Blade instead derives row totals directly from quantity and unit price. `PosReceiptService` supports several metadata variants but falls back to that same multiplication when amounts are absent. POS formatting already supports two decimals; receipt monetary formatting uses zero decimals.

Existing `transaction-row-total-rounding`, packed pricing, manual override, and bundle specifications remain authoritative. Loading or printing a document must not trigger repricing.

## Goals / Non-Goals

**Goals:** Preserve a single captured set of monetary amounts across save, reload, completion, presentation, settlement, reporting, and returns; retain decimal precision; enable controlled repair of affected drafts.

**Non-Goals:** Changing rounding increments or eligibility, rounding unit prices to the business increment, repricing completed transactions, changing Purchase behavior, or rebuilding already-correct allocation services.

## Decisions

1. **Persist one calculated snapshot atomically.** Save header and calculated commercial rows from the same snapshot, preserving row identity, source pricing metadata, serials, and bundle composition. Reuse the current mapper and minor-unit conventions. Include gross, actual row discount, rounded pre-bill net, allocated bill discount, tax, and charged total with explicit semantics. Audit both draft and completion entry points. Passing raw rows alongside calculated headers is rejected because it discards the pricing result.

2. **Share persisted amount resolution.** Resolve automatic, packed, and manual rows through a common service/helper consumed by detail and receipt mapping. Prefer authoritative net metadata; distinguish row discounts from bill discounts and rounding adjustment. Keep the rounding adjustment internal. Customer-facing receipts and details show authoritative row totals without rounding wording or a separate rounding adjustment row, as requested by the user. Unit price remains the captured unit price; never reconstruct a committed total from a two-decimal blended price. Do not repurpose `line_net_minor` as an automatic-row field without checking override detection: existing code uses canonical override metadata as an authority signal.

3. **Retain currency precision in presentation.** Reuse existing locale conventions, showing up to two decimal places without hiding nonzero cents for monetary values in receipt, detail, and POS transaction listing. Keep the working POS formatter unless verification identifies a gap. Formatting must not apply business increment rounding.

4. **Consume captured amounts downstream.** Checkout fragments, generated sale totals, payment allocations, report totals, and return valuations must use the same committed charged values. Reuse deterministic minor-unit allocation. Global discounts can produce totals outside the increment; owner and return fragments must not be rounded to the increment again. Tendered cash and change retain their existing meaning: cash received less change, other applied payments, and outstanding debt reconcile to the amount billed.

5. **Explicit draft repair with conservative eligibility.** Provide a setting-scoped Artisan operation requiring explicit transaction IDs, with a read-only preview as default and explicit apply mode. Recover missing row amounts from captured pricing inputs and the stated current increment only when the resulting complete snapshot reconciles exactly to the existing header. Preserve manual overrides and existing authoritative values. Show the increment, before/after row amounts, and unchanged header in preview. Refuse ambiguous recovery, incompatible historical pricing/tax context, loaded/active drafts, completed/cancelled records, and header mismatches; do not invent an allocation of a header discrepancy. Apply under transaction and row lock after revalidating status and snapshot hash; retain an audit record of actor, inputs, increment, and before/after snapshots and regenerate the snapshot hash. Repeat application is a no-op. For 3393, the expected repair fills 26000 for the affected row while retaining 318000 at the header, subject to live revalidation.

6. **Legacy reads remain stable.** Missing historical metadata uses documented legacy fallback without querying current prices or rerounding. Completed data remains unchanged even when incomplete; do not claim an inferred value is historically authoritative. Legacy inconsistencies that cannot be repaired safely remain identifiable for separate investigation.

## Risks / Trade-offs

- Ambiguous `line_total` units and override flags → centralize explicit precedence and cover packed, automatic, and manual fixtures.
- Applying discounts twice or losing the rounding delta → retain separate gross, discount, pre-bill net, bill allocation, and charged amounts and assert reconciliation.
- Historical increment may differ from current settings → preview records the current increment; refuse recovery when evidence does not reconcile, and never repair on read.
- Active cart races during repair → exclude loaded/active drafts, lock and revalidate on apply, and test concurrent-state rejection.
- Decimal formatting changes receipt width → provide a human-only browser/print-preview checklist for compact receipts, including large amounts and nonzero decimals. Automated verification covers server-rendered monetary content without opening a browser.

## Verification Plan

Run only focused Laravel test files or filters covering affected persistence, monetary resolution, downstream reconciliation, and repair behavior in an isolated test database. Reuse existing focused coverage where sufficient. Do not run or require the full application suite, an unfiltered `php artisan test`, or `composer test:fresh-sqlite` for this change. OpenSpec validation and a scoped diff review complete automated verification.

All browser testing is human-only. The agent must not launch browser automation or perform browser checks. Provide a short checklist for POS decimal display, transaction-detail/receipt agreement, and compact receipt print layout with large and fractional amounts. Report these checks as pending human verification until a human supplies results; their execution is not an agent implementation task.

## Migration Plan

Deploy persistence and presentation changes with focused regression tests. Prefer existing JSON metadata and audit facilities; add schema only if existing audit storage cannot preserve the required repair evidence. No automatic data rewrite runs during deployment. Run preview for explicitly selected affected drafts; applying any real repair is a separate operational action. Implementation and proposal creation do not themselves alter transaction 3393.

Rollback application changes without deleting captured amount metadata or audit records. Restoring an explicitly repaired draft requires its audit snapshot and renewed status/hash checks; completed documents are never reverted by the draft operation.

## Open Questions

No user decisions block implementation. Confirm the repository's active-cart detection and audit-record conventions during implementation; these affect mechanics, not the agreed repair boundaries.
