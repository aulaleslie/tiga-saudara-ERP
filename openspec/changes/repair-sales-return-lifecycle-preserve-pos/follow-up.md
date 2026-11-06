# Follow-up after the Sales Return repair

Recorded: 2026-11-06 (Asia/Makassar).

This is a continuation record for `repair-sales-return-lifecycle-preserve-pos`. It preserves what to do after implementation and verification, without authorizing deployment, historical corrections, or additional features. The current implementation checklist is [tasks.md](tasks.md); behavior and architecture are in [proposal.md](proposal.md), [design.md](design.md), and `specs/`.

## Current handoff

Planning is complete; implementation has not started as of this record. Start with `/opsx-apply repair-sales-return-lifecycle-preserve-pos`. Follow the dependency order: stabilize POS, isolate workflows, repair standard returns, then verify combined behavior. Do not start standard shared-service changes against the currently failing POS baseline.

Baseline captured during exploration:

| Run | Result | Interpretation |
| --- | --- | --- |
| Standard-related SaleReturn/SalesReturn filter | 19 failed, 34 passed | Fourteen fixture failures; five missing selectable-method failures. |
| Focused POS Return classes | 27 failed, 357 passed | Four failures trace to two application defects; four concern fixtures/error expectations; nineteen stop at monetary validation and require rerunning after fixture repair. |
| Broader POS filter containing Return | 37 failed, 495 passed | Also matches unrelated test names containing “returns”; do not use as the clean return baseline. |

These are historical observations, not current verification results. Capture fresh exact commands and outputs during implementation. Temporary files under `/tmp` are not durable evidence.

## Decisions that must survive implementation

- POS-origin sales use POS Return exclusively. Standard mutation surfaces cannot act on linked POS records, even when the user holds both permission sets.
- Preserve established POS atomic approval, cumulative rounding, bundle scope, split-owner/location/tax handling, serial lineage, and non-serial note-only replacement. The agreed fixes correct defects; they do not redesign POS business behavior.
- Standard settlements offer repair/replacement, cash return, and cannot process. Historical credit/invoice-adjustment labels stay readable.
- Standard received goods remain held outside sellable stock until authorized inspection releases eligible retained goods. Original repair/cannot-process goods leave custody through approved dispatch. Replacement stock and returned original custody are separate.
- Standard cash returns reduce debt first, refund the remainder within verified payments, and record each effect separately. Debt-only completion creates no cash payment. Zero cash must never silently become a full refund.
- Every received item/serial must reach final settlement before the return completes. Only final cash-return coverage can archive the standard source sale; service-return completion leaves sale finances unchanged.
- Historical records are audited without automatic correction. Ambiguous payment allocation or mixed-workflow evidence must not be guessed.

## 1. Close implementation with evidence

Before treating this change as done:

- Complete and verify all tracked tasks, including the nineteen previously blocked POS execution cases reaching their intended assertions.
- Save exact focused standard/POS, fresh SQLite, and isolated MySQL/MariaDB concurrency results in the repair guide. Identify unrelated pre-existing failures separately; do not accept unresolved failures in the return compatibility gate.
- Review synthetic end-to-end examples for cash refund, debt-only return, repair, replacement, cannot process, partial inspection, repeated actions, and attempts to bypass POS orchestration.
- Confirm custody conservation, no duplicate financial effects, no premature archival, correct owner buckets, and no historical mutation.
- Resolve the existing malformed main `sale-return-completion-archival` structure through task 8.5. Change validation currently passes but reports that archival would refuse that main-spec target until its structure is normalized.

## 2. Review and archive the completed change

Review the delivered behavior against the seven capability deltas and implementation evidence. Invoke the explicit OpenSpec archive workflow after implementation and verification are complete. Ensure the deltas are integrated into main specs exactly once and the historical change remains available.

Keep this follow-up document with the archived change. If a later task needs its context, locate it under the archived directory rather than recreating the discussion from memory.

## 3. Prepare operational rollout

Use the implemented repair guide as the authoritative runbook. Before deployment:

- Rehearse additive migrations and standard/POS separation with synthetic or appropriately isolated staging data.
- Identify who will receive the dedicated inspection permission; register the permission without silently granting it to all existing roles.
- Explain to operators that financial completion does not make held goods sellable, where to inspect/release goods, and how to follow POS navigation or legacy-action blocks.
- Establish how held goods will be physically identified and reconciled against the separate custody view.
- Confirm the rollback boundary: once new custody/adjustment evidence exists, retain its schema and block unsafe standard mutations rather than reverting to old stock-activation behavior.

Deployment is a separate operational action; this document does not authorize it.

## 4. Run the read-only historical audit

After the audited code is available, run the delivered audit command in explicit business/source/return scope. Preserve the output and its run date as evidence. The planned command name is `sale-returns:audit`; use the final implemented flags from the repair guide rather than assuming an interface now.

Triage findings in this order:

1. Duplicate or excessive payouts and debt adjustments.
2. Stock/serial inconsistencies, including original serials sent back without matching inventory evidence.
3. Returns or source sales completed/archived without full valid settlement coverage.
4. Standard/POS claims mixed on one source sale, ambiguous payment allocation, and already-received legacy returns lacking trustworthy custody evidence.

Distinguish proven, suspected, and ambiguous findings. Current aggregate stock alone cannot establish what happened historically; later legitimate movements may explain it. Audit output must never directly trigger data correction.

## 5. Propose historical remediation separately where needed

Historical repair was deliberately excluded from this change. For each proven issue that needs correction, prepare a separate scoped proposal with:

- Exact business, source sale, return, settlement, serial, stock-location, and payment identities.
- Original evidence, later related movements, and a reproducible explanation of the inconsistency.
- A dry-run before/after plan, invariants to preserve, and explicit financial/stock/report effects.
- Actor/reason audit, stale-evidence checks, atomicity, repeat safety, and rollback or compensating-action strategy.
- Review of existing POS balance and replacement-stock repair tools before introducing another tool; reuse only if their evidence and scope actually fit.

Do not reopen or recalculate completed returns globally. Existing production transaction examples, including transaction 9218 from POS history, are not mutating test fixtures. Ambiguous findings stay unresolved until the missing evidence is established.

## 6. Track deferred product work only if evidence warrants it

These are possible later changes, not commitments or unfinished tasks in this repair:

| Deferred topic | When to consider it |
| --- | --- |
| Unified standard/POS return entitlement | Only if users need both entry points for POS-origin sales; requires a shared model for original quantities, active reductions, serial occurrences, and financial claims. |
| Legacy payment-allocation reconciliation | When real blocked cases lack reliable source-sale payment attribution; establish evidence rather than adding a fallback guess. |
| Historical custody migration | When already-received legacy goods need operational continuation and physical/evidence reconciliation can prove their balances. |
| Additional custody/reporting workflows | When operators identify requirements beyond the delivered held/released/customer-dispatched view; do not silently merge held goods into sellable counters. |
| Unrelated POS test repairs | Track the unrelated failures discovered by the broad substring run independently of return compatibility. |

## Resume prompt

> Read the archived or active `repair-sales-return-lifecycle-preserve-pos` proposal, design, specs, tasks, follow-up document, and delivered repair guide. Check actual implementation/test/deployment status before proceeding. Preserve established POS behavior. Determine whether the next action is verification, archival, rollout preparation, read-only historical audit, or a separately proposed evidence-based repair. Do not infer permission to deploy or change historical data from this document.
