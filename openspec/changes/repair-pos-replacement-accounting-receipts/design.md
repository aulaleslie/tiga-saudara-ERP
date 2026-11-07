# Design

## Context

See proposal.md for motivation and the three delta specs for the behavior contract. This is Laravel 10 / Livewire 3 with nwidart modules. The existing POS return lifecycle executes approval effects in a database transaction.

Observed transaction 10717 / checkout 10438 has original charges Rp2,600,000. Source sale 214729 still has total and paid Rp2,270,000, while printer detail 430485 is zero and paper detail 430486 is Rp45,000. Generated replacement sale 214745 and its payment add Rp2,225,000. Unrelated sale 214728 is Rp330,000. The projection reproducibly returns Rp4,825,000 and correct replacement serial XE4Q318288.

`executeResolvedLineEffects()` reduces cross-owner source details, calls `recalculateCashCorrectedSourceSale()`, then creates replacement sales. That recalculation subtracts only commercial cash-return amounts when other source rows remain. `executeCrossOwnerReplacementForDetail()` independently creates a fully paid sale and payment. `PosSettlementProjectionService` sums reachable sale headers and effective payments; `computeAssignedLineAmounts()` distributes each sale header over its remaining details. These individually consistent projections propagate the duplicated header rather than detect its replacement origin.

Existing receipt tests include cross-owner and serial-chain cases but use manually assembled accounting fixtures; add lifecycle-driven mixed-product coverage. `PosReturnBalanceRepairService` explicitly rejects non-cash resolutions and overpayments; do not silently broaden its contract. The main cross-owner spec currently covers calendar-date preservation; retain it. Main receipt specs already require current prices, conserved replacement value, historical access, and read-only printing. The new deltas add explicit accounting conservation and duplicate detection without replacing those contracts.

The IDE-referenced `repair-sales-return-lifecycle-preserve-pos` directory is absent in this checkout. Coordinate integration if it reappears; do not create or overwrite it. Root Spec Kit artifacts and OpenSpec history remain intact.

## Goals / Non-Goals

**Goals:** One conserved customer charge and collection across owner movements; shared accounting evidence for live execution and historical repair; deterministic reconciliation before commit.

**Non-Goals:** Receipt-only overrides, rewriting checkout snapshots, redefining cash-return settlement, changing original payment collection facts, or replaying inventory/HPP/serial effects during accounting repair. Do not infer payment provenance from a generated reference string alone.

## Decisions

### 1. Separate commercial transfer from cash-return correction

Introduce a replacement accounting service used by the lifecycle and repair planner. Capture the current source charge before detail reduction, determine the affected charge from persisted return execution/owner allocations, reduce the source header by that exact charge, and add it once to the replacement sale. Use integer minor units and deterministic remainder allocation. Preserve unrelated header charges and legitimate monetary edits; reuse persisted tax/discount allocation evidence, not a quantity-times-price reconstruction. Reject unsupported allocations before committing effects.

For mixed cash-return and replacement execution, compute both effects from one captured pre-execution state and apply each once. Do not rerun the cash-return decrement on an already corrected header. Same-owner, note-only, and informational component paths keep their existing behavior.

Alternative rejected: summing remaining detail subtotals unconditionally loses legitimate header discounts, tax, shipping, and rounding. Changing only the existing cash-return subtraction would leave duplicate payments.

### 2. Persist owner payment allocation transfers without new collections

Add module-owned allocation-transfer persistence linking checkout group, return detail, source/replacement sale, original payment, method, amount, predecessor transfer where applicable, actor, and execution timestamp. Enforce unique execution lineage and original-payment allocation bounds. Keep original payment amount, date, reference, method, and attachments unchanged. Replacement sale payment value must come from this evidence rather than an unbacked new ACTIVE collection row.

Extend effective-paid computation to subtract outgoing and add incoming allocations exactly once. Update `Sale::getEffectivePaidAmount()`, the eager-loaded settlement fast path, receipt payment-method reconciliation, and collection/report consumers to share the same semantics. Raw collection totals still count original receipts once; transfer records are owner attribution, not money received. Existing payment invalidation/credit application semantics remain effective and must prevent invalidated evidence being reused.

When payment evidence explicitly assigns a paid portion, preserve that allocation. Otherwise, for a valid source sale with unassigned payments, allocate paid value proportionally to transferred commercial charge relative to pre-transfer source charge, in minor units; distribute methods deterministically by existing available method amounts. Transfer no paid value for an unpaid sale, and move the remaining charge as debt. This is the implementation assumption for partially paid cases; conservation, bounds, and provenance are mandatory. Later payments and price edits remain ordinary transactions against the then-current sale allocations.

For historical generated replacement payments, retain their original facts, mark proven synthetic duplicates invalidated using existing audit fields, and replace their effective contribution with a linked transfer. Do not invalidate an independently collected payment. If that distinction is unprovable, block repair.

Alternative rejected: editing original collection amounts destroys historical evidence; continuing to create ordinary paid replacement records inflates collection reporting. A transfer ledger adds integration work but keeps both customer collections and owner settlement explicit.

### 3. Validate the whole checkout group and retain read-only projections

Lock source/replacement accounting and payment evidence in stable ID order under the existing lifecycle transaction. Validate aggregate charge, effective paid, debt, owner balances, lineage, and receipt row reconciliation before commit. Resolve repeated replacements from the current owner and preserve the chronological serial chain. Unique event keys and lifecycle guards prevent retries from replaying transfers.

Keep all completed receipt paths on the shared projection. Add evidence-based duplicate-replacement validation before header allocation; proven stale replacement headers must trigger the existing actionable refusal/historical path, not get allocated onto paper or other unrelated rows. Legitimate independent header edits must remain supported. No financial mutation occurs while printing; successful PRINT/REPRINT audit behavior remains as established.

### 4. Dedicated historical repair with reviewed fingerprints

Add `pos:repair-replacement-accounting` with required `--setting`, read-only `--discover`, repeatable explicit `--transaction`, and preview by default. Discovery cannot combine with apply. Apply requires explicit selection, `--actor`, `--reason`, and the reviewed per-checkout evidence fingerprint. Detailed reports include linked records, existing and proposed charge/paid/debt per owner, method allocations, expected receipt lines, blocking reasons, and fingerprint. Discovery is bounded/paginated and reads only within the business scope; mutations may reach proven linked replacement owners.

Plan by replaying completed non-reversed replacement accounting evidence chronologically without replaying physical effects. Preserve authoritative row charges and later edits. Block missing payment origins, ambiguous mappings, unsupported tax/discount allocations, active overlapping returns, and unexplained drift. Cash-only repair remains separate.

Apply one checkout group atomically under locks, replan and compare fingerprint, persist header/allocation changes and an additive repair audit, then verify the normal receipt and settlement services. Record actor/reason, before/after data, original/generated payment identifiers, and evidence hash. A corrected state reports unchanged; stale plans refuse and require new review. A failure rolls back that group. Do not delete or alter original collections or replay return completion.

### 5. Refund reconciliation of transfer chains with reversal evidence

When a cash refund exceeds what a replacement sale should retain, `PosReturnLifecycleService` trims only incoming transfers backed by active payments, newest first, never below the amount consumed by downstream transfers. `reduceTransferChain()` reduces the transfer, recurses through each predecessor by the same amount (child before parent, to satisfy predecessor bounds), and at the chain root reduces only the specific backing payment through `SalePayment::reduceSpecificActivePayment()`, which invalidates that payment, creates an active remainder, and re-points its transfers. The sale-wide reconciler is deliberately not used because it trims the newest payments and could invalidate another owner's allocation.

Every reduction or removal first writes a `pos_owner_payment_transfer_reversals` row (transfer snapshot, before/after minor amounts, actor, sale return, deleted flag, reason). The table is append-only, has no foreign key to the transfer so evidence survives removal, and is additive.

Historical repair replays chronologically; the provenance check for a source sale counts initial genuine payments plus allocations planned into it earlier in the chain, and replacement-header consistency is validated per sale only after the full replay. Outgoing transfers eager-load their backing payments to satisfy lazy-loading protection.

**Locking (verified on a disposable MySQL 8.0):** lock order is sales, then `sale_payments`, then `pos_owner_payment_transfers`; `reduceTransferChain()` locks the backing payment before the transfer, and the lifecycle already holds the refunded sale. `PosOwnerPaymentTransfer`'s saving hook reads the backing payment, predecessor and existing allocation sums with locking reads, because a plain read inside a REPEATABLE READ transaction sees a stale snapshot (a concurrent refund otherwise failed with "payment does not exist" and the amount-bound check could undercount). A refund inserting a replacement payment needs the parent sale row, which a concurrent repair holds while it waits for the refunded sale, so MySQL can pick the repair as a deadlock victim; `PosReplacementAccountingRepairService::apply()` therefore runs with 3 transaction attempts, and each attempt revalidates the reviewed fingerprint under lock (a changed state refuses instead of applying).

## Risks / Trade-offs

- [Effective-paid consumers bypass a shared accessor] → Cover both eager-loaded and query paths, receipt method totals, collection reports, and owner balances with integration tests; audit direct ACTIVE-payment sums when implementing.
- [Historical edits resemble duplication] → Require complete replacement and payment evidence; report ambiguous candidates for manual review rather than restoring snapshot totals.
- [Repeated or mixed returns subtract twice] → Pre-execution state, chronological lineage, unique transfer keys, and lifecycle-driven mixed/retry tests.
- [Tax and bundle allocations differ from customer line prices] → Preserve existing allocation evidence and minor-unit arithmetic; retain nested components under their parent; refuse unsupported evidence.
- [Other pending lifecycle changes overlap] → Rebase accounting integration against the actual checkout before apply and preserve independent lifecycle/custody work.

## Migration Plan

The existing main `pos-return-cross-owner-replacement/spec.md` begins with a delta header instead of main-spec Purpose/Requirements sections. Strict change validation reports this pre-existing archive blocker. Normalize that main-spec structure during implementation while preserving its entire calendar-date requirement and scenario, then revalidate before archive.

1. Add allocation-transfer, repair-audit, and transfer-reversal tables through additive module migrations with MySQL and SQLite compatibility. No migration performs a historical backfill or repair.
2. Deploy conserved live execution, effective-paid consumers, and receipt validation together after focused lifecycle, report, and repair tests pass. Existing inconsistent receipts may refuse until explicitly repaired.
3. Run business-scoped discovery and preview transaction 10717. Present before/after evidence for review; do not apply historical repairs automatically during implementation or deployment.
4. After separate approval, apply only reviewed explicit transactions with matching fingerprints. Verify current receipt Rp2,600,000 and serial XE4Q318288, historical facts, owner balances, and collection totals.
5. On failure, the transaction rolls back. For rollback after committed transfers, retain ledger/audit data and use a reviewed forward correction; do not roll application code back to readers that ignore transfer evidence or destructively drop populated tables.
