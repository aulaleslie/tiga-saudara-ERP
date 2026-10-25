# Design

## Context

See proposal.md for motivation. Read-only investigation found transaction 9218 has three owner sales. Their remaining detail sums are Rp3,840,000, Rp4,300,000, and Rp21,965,000. The last header stores Rp21,964,999.60. The reprint service proportionally allocates each sale header across its commercial rows, spreading the shortage across many printed totals. The current allocation algorithm produces the expected rounded rows when given corrected headers in memory.

## Goals / Non-Goals

**Goals:** Restore proven header authority from verified historical evidence, including current receipt and debt consistency; retain a narrow write set and reviewable preview.

**Non-Goals:** Reprice historical sales, force every database detail to today's increment, rewrite completed return values, repair ambiguous row corruption, alter payment classification, or run the full test suite. Rp375,000 remains recorded as paid, regardless of conversational use of “potongan”.

## Decisions

### 1. Explicit CLI and classifications

Use `pos:repair-return-balances --setting=1 --transaction=9218` with repeatable transaction options. `--apply --actor=<id> --reason=<text>` enables audited application. Validate actor identity and eligibility using existing privileged correction conventions. Require nonempty reason on apply. Report repairable, unchanged, and blocked candidates with evidence and current/proposed sale headers, commercial rows, return quantities/values, paid amounts, due amounts, and receipt totals. Return nonzero for blocked selections or failed applications; commit each selected checkout group atomically so other selected groups can still be reported.

### 2. Conservative evidence gate

Resolve the whole checkout group and its reachable owner sales, including any other POS transactions sharing that checkout. Scope-check the selected transaction's POS business; source owner businesses may differ legitimately. Load original captured row totals, immutable checkout owner allocation, linked sale details, all return/reversal states, and payment/adjustment evidence. Compare in integer minor units. Require an unambiguous mapping and completed non-reversed cash-return history, no active overlapping return, no unexplained monetary edits, and no unsupported bill adjustments.

Prove remaining quantity and value per commercial row from captured charge minus actual committed returns, then verify current rows and owner allocation. Accept a header mismatch only when historical unit-price reconstruction explains it exactly and captured original owner charges less actual committed returns independently agree with remaining rows. A small difference alone is insufficient. Inconsistent rows, incomplete source mapping, unsupported bundles/partial-return evidence, overpayments, or unexplained tax/header adjustments block the group with a reason. No guessed recovery or forced rounding.

This deliberately preserves all row values in the first implementation. The user-visible row problem in 9218 comes from header allocation; changing correct persisted rows would introduce risk. Decimal manual amounts and deterministic owner remainders remain valid.

### 3. Minimal writes with atomic audit

Introduce a command and a dedicated planning/application service. Register `Modules\Pos\Console\PosRepairReturnBalancesCommand` via `PosServiceProvider::boot()`.

**Serialization Hierarchy & Writers:**
To ensure mutual exclusion with all affected writers (return approval in `PosReturnLifecycleService`, payment settlement in `PosKasBonSettlementService`, and return submission in `PosReturnSubmissionService`), all mutations are wrapped in a single database transaction. Under this boundary, entities must be locked using `lockForUpdate()` in a deterministic ascending ID order:
1. `PosTransaction` rows sorted by `id ASC`
2. `PosCheckout` rows sorted by `id ASC`
3. `PosReturn` rows sorted by `id ASC`
4. Reachable `Sale` rows sorted by `id ASC`

This covers all affected writers touching checkout groups, sales, transactions, and returns, preventing deadlocks and racing mutations.

**Audit Mechanism:**
Because existing audit logs do not support durable structured before/after values and transactional rollback, an additive table `pos_return_balance_repair_audits` is introduced via a dedicated migration. It stores `pos_transaction_id`, `pos_checkout_id`, `sale_id`, `actor_id`, `reason`, `evidence_fingerprint`, `before_values` (JSON), `after_values` (JSON), and timestamps.

Plan before mutation, then lock the transaction/checkout group and affected sales in the stable ID order described above. Re-read return, payment, adjustment and row evidence under that boundary; fingerprint all inputs that determine the plan. Refuse a changed plan. Update only proven `total_amount`, `due_amount`, and any derived status field whose existing invariant requires it. Keep `paid_amount` unchanged and calculate due by subtraction. Do not recompute inclusive tax, update checkout-time owner allocations, or replay model lifecycle effects that post inventory or payments.

Within the transaction, run the current receipt and settlement projection against fresh corrected models. Require rows, total, payment and debt to reconcile; rollback on projection failure. Persist the audit record with source IDs, reason, actor, fingerprint, and before/after values in the same transaction. Already consistent records are no-ops with no new corrective audit.

### 4. Focused acceptance evidence

Build synthetic fixtures reproducing the 9218 owner split, complete 8+2 bottle return, Rp790,000 marker row at a blended Rp6,583.33, and Rp375,000 paid. Verify dry-run immutability, protected evidence, blocked ambiguity, changed evidence, atomic rollback, idempotency, and real receipt projection before/after repair. After repair, all remaining commercial row totals in this fixture must be multiples of Rp100, grand total Rp30,105,000, paid Rp375,000, due Rp29,730,000, and fully returned ink absent from current items but present in return history. Include a legitimate decimal/manual-value case to reject blanket rounding. Run only the focused new tests and directly affected existing receipt-return projection tests, plus OpenSpec validation and PHP syntax checks for changed PHP files.

## Risks / Trade-offs

- [Incomplete legacy evidence] → Block rather than infer an intended price; support can expand through a later scoped change.
- [Concurrent payment/return] → Reuse common locking boundaries, revalidate fingerprints and rollback atomically.
- [Receipt allocation masks unrelated adjustments] → Prove the discrepancy before correcting it; retain existing receipt allocation for legitimate adjustments.
- [Historical protected-record rules] → Explicit spec exceptions apply only to this dedicated command; tests use synthetic records and never mutate live transaction 9218.

## Migration Plan

Deploy the additive command/service and audit schema if required; deployment performs no historical repair. Run explicit-ID preview and review output before a separately requested operational apply. Proposal and implementation do not themselves authorize running apply against live data. Reversal of a mistaken correction requires audited review of unchanged evidence and recorded before-values; never blindly restore balances after later payments or returns. Removing the command does not erase completed audit evidence.
