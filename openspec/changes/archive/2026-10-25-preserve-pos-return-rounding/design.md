# Design

## Context

See proposal.md for motivation and the three delta specs for behavioral contracts. `PosTransactionLineAmountResolver` resolves stored POS charged values; `RowTotalRoundingCalculator` already implements half-up increment rounding in integer minor units. `PosReturnApprovalPreviewPlannerService` builds execution amounts. `PosReturnLifecycleService::recalculateCashCorrectedSourceSale()` feeds remaining details to `SaleNormalizer`, whose pre-tax reconstruction can discard rounded line authority. Fix the POS return boundary rather than changing generic normalization across the ERP.

## Goals / Non-Goals

**Goals:** Share one deterministic valuation between preview and execution; retain unaffected monetary authority; reconcile rounded commercial returns through owner splits and settlement; support older source sales without rewriting history.

**Non-Goals:** Checkout repricing, currency-display changes, generic Sales/Purchase return redesign, replacement pricing changes, historical repair, and full-suite testing. Transaction 9218 is investigation evidence only and must not be modified during deployment or verification.

## Decisions

### 1. Maintain a durable sale context and per-commercial-line cumulative ledger

Add nullable versioned JSON return-rounding context to source Sales via an additive POS-owned migration. Persist policy version, POS business ID, frozen increment and context creation evidence; per commercial line record immutable baseline quantity/returnable amount, baseline historical returned quantity/value, committed new quantity/value, and last allocation reference. Retain per-return calculation inputs and results in return-line metadata/execution audit evidence. Resolve commercial identity through the source POS transaction line, including stable bundle-parent identity; split-owner sales share the same commercial calculation and reconcile by allocation. Initialize affected sale contexts together in the approval transaction.

Use the POS transaction's business increment, not the user's active business or a fragment owner's setting. Freeze it at the first successfully committed cash return for each sale under this policy. Preview resolves a provisional value only; lock and compare at approval. Existing historical returns seed immutable baseline evidence without acquiring a fabricated historical policy. If evidence cannot establish quantity, charged value and residual unambiguously, block approval with an actionable reason instead of inventing a valuation.

Alternative: look up the current setting every time. Rejected because setting changes would change repeated partial-return results. Snapshotting on draft creation is also rejected because abandoned drafts must not freeze policy.

### 2. Round cumulative commercial return value, then take the delta

Use integer money and exact supported quantity precision for proportional calculations. Let A be the original authoritative returnable charge, Q the original quantity, q the cumulative eligible cash-returned quantity, H the actual previously committed return value, and I the frozen increment:

- If q exhausts Q, target cumulative return is A.
- Otherwise target is clamp(round_increment(A × q / Q, I), H, A).
- Current return is target minus H, capped by the actual stored returnable residual.

A includes the original allocated bill discount and applicable tax; do not multiply a truncated stored unit price. With A=790000, Q=120, I=100, first q=1 gives 6600 and remaining 783400. For q=3 the cumulative target is 19800; q=4 gives 26300, so the fourth request is 6500. This illustrates why repeated requests cannot each independently round one unit. Full exhaustion consumes the exact residual, including legacy discrepancies; never silently repair legacy amounts. Zero increment retains currency precision. Reject ambiguous or negative residual evidence. Zero monetary deltas with valid physical quantity remain permissible and auditable under coarse increments.

Only executed, non-reversed cash-return quantity/value consumes this ledger. Rejected, abandoned and rolled-back attempts do not consume it. Existing reversal flows must release their recorded allocations atomically, using audit evidence rather than recomputing historical refunds; subsequent previews use the resulting active state.

Alternative: round each request separately or round the remaining balance. Rejected because partitioned requests could change refunds or introduce drift.

### 3. Correct remaining values directly at the POS boundary

Preserve unchanged detail subtotal, tax and discount values. Changed commercial detail values are their stored values minus the planned correction; allocate the correction deterministically across owners, bundle parent/components, tax and pre-tax fields using established minor-unit remainder rules. Do not independently increment-round any fragment. Rebuild Sale headers from these authoritative remaining monetary fields and existing retained charges. Supply explicit authoritative subtotal/pre-tax/tax inputs where normalization is retained, or aggregate at the POS correction boundary; do not globally reinterpret SaleNormalizer inputs. No unit-price reconstruction or second increment-rounding pass.

Keep existing tax eligibility, discount handling, stock, serials, owner mapping and replacement semantics. Preview, linked Sales Returns, effective debt reduction, refund evidence and source correction must consume the same amount. Where paid and unpaid portions coexist, refund plus debt reduction equals the correction, not two separate uses of it.

### 4. Serialize calculation and commit

Lock affected sale contexts/details in deterministic ID order, revalidate cumulative quantity/value, current source state and provisional configuration against preview, then commit allocations and all existing approval effects atomically. A stale preview must refresh before confirmation. Reuse lifecycle idempotency guards; retries do not add allocation evidence twice. Failed approval rolls back policy initialization as well as money/stock changes.

## Risks / Trade-offs

- Legacy history may not reconcile to original checkout amounts → seed from actual audited balances and block ambiguous cases; do not backfill or repair.
- Bundle quantities and owner fragments can duplicate a parent value → calculate once at commercial identity, allocate only afterward, and verify summed fragment amounts.
- Source normalization may reintroduce unit-price drift → focused regression with an untouched Rp790000 marker line and truncated Rp6583.33 price.
- Configuration changes or simultaneous returns can invalidate previews → approval locking and stale-state checks cover both context initialization and cumulative consumption.
- Final residual may fall outside the configured increment → exact conservation takes precedence on final exhaustion, as agreed.

## Migration Plan

Deploy additive nullable context storage without populating historical rows; initialize only during future successful cash-return execution, including against older sales. Reads and previews are non-mutating. No data repair commands, production transaction mutations, or special-case monetary changes for 9218. Roll back code only after preventing writes through the new path; retain committed context/audit evidence and do not reverse financial effects automatically.

## Verification

Focused calculation tests cover half-up, zero increment, cumulative partition independence, caps, final residual and historic baselines. Focused POS return integration tests cover unaffected rounded lines, tax/discount reconciliation, owner/bundle allocation, paid/debt settlement, frozen settings, stale previews, concurrency guards, rollback, retries and reversal allocation release. Use synthetic fixtures, including a 9218-shaped case, rather than modifying transaction 9218. Run only new and directly affected existing test classes/filters using `php artisan test`; no full suite or `composer test:fresh-sqlite` run is planned.
