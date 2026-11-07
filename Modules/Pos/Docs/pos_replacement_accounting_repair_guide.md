# POS Replacement Accounting Repair Command: Operational Runbook & Procedures

## 1. Overview & Purpose

`pos:repair-replacement-accounting` is a dedicated, business-scoped CLI tool for discovering, previewing, and atomically repairing proven historical accounting duplication caused by completed cross-owner POS product replacements (such as incident transaction `10717` / checkout `10438`).

> [!IMPORTANT]
> - This command is strictly separate from `pos:repair-return-balances` (which repairs cash-only return header rounding drift).
> - Discovery (`--discover`) and preview (`--transaction=<id>` without `--apply`) are **100% read-only** and perform zero database writes.
> - **Never apply historical repairs without separate review and explicit approval** of the preview output and `evidence_fingerprint`.

### Historical Defect Context (Incident Transaction 10717 / Checkout 10438)
In historical cross-owner POS product replacements:
1. Original customer checkout `10438` (transaction `10717`) totaled **Rp2,600,000**:
   - Owner A (`Sale 214729`): Epson Printer **Rp2,225,000** (`SN XE4Q304308`) + SIDU Paper **Rp45,000** = **Rp2,270,000**
   - Unrelated Owner (`Sale 214728`): Logitech **Rp330,000**
2. When the customer replaced the printer with a serial owned by Owner B (`SN XE4Q318288`), historical execution:
   - Reduced the printer `SaleDetails` row on `Sale 214729` to `0`, leaving only the **Rp45,000** paper row active.
   - Left `Sale 214729` header `total_amount` and `paid_amount` at **Rp2,270,000** (because `recalculateCashCorrectedSourceSale` subtracted only cash returns when other active rows remained).
   - Created replacement `Sale 214745` under Owner B for **Rp2,225,000** and an unbacked active `SalePayment` (`CROSS-OWNER-REPL/...`) for **Rp2,225,000**.
3. As a result, settlement projection summed **Rp2,270,000 + Rp2,225,000 + Rp330,000 = Rp4,825,000**, and receipt projection allocated the unreduced **Rp2,270,000** header of `Sale 214729` onto its single remaining active row (SIDU Paper), inflating the Rp45,000 paper line to **Rp2,270,000**.

---

## 2. Command Usage

### 2.1 Bounded Read-Only Discovery (`--discover`)
Scans completed, non-reversed POS returns within the specified business setting (`--setting`) that contain cross-owner replacement evidence, and reports each candidate's classification (`REPAIRABLE`, `UNCHANGED`, or `BLOCKED`) and `evidence_fingerprint` without modifying any database records.

```bash
php artisan pos:repair-replacement-accounting --setting=<setting_id> --discover --limit=50
```

For structured JSON output:
```bash
php artisan pos:repair-replacement-accounting --setting=<setting_id> --discover --limit=50 --json
```

### 2.2 Read-Only Detailed Transaction Preview (Default)
Inspects one or more explicit POS transactions (by numeric ID or transaction code) within their owning business setting. Performs zero writes to monetary, physical, or audit tables.

```bash
php artisan pos:repair-replacement-accounting --setting=<setting_id> --transaction=10717
```

Structured JSON preview:
```bash
php artisan pos:repair-replacement-accounting --setting=<setting_id> --transaction=10717 --json
```

#### Preview Verification Checklist
Before approving any repair, verify all of the following in the preview output:
1. **Classification**: Must be `[REPAIRABLE]`.
2. **Evidence Fingerprint**: Copy the exact 64-character SHA-256 `evidence_fingerprint`.
3. **Summary Totals**:
   - `Proposed Grand Total` matches the conserved customer purchase total (e.g., `2,600,000.00` for transaction `10717`).
   - `Proposed Effective Paid` matches the conserved customer payment total (`2,600,000.00`).
   - `Duplicated Charge` and `Duplicated Paid` match the cross-owner replacement value (`2,225,000.00`).
4. **Per-Owner & Per-Sale Breakdown**:
   - Source owner `Sale` header reduces by the exact transferred replacement charge (e.g., `2,270,000.00` -> `45,000.00`) and its effective paid reduces via outgoing `PosOwnerPaymentTransfer`.
   - Replacement owner `Sale` retains its replacement charge (`2,225,000.00`) and its effective paid (`2,225,000.00`) is backed by an incoming `PosOwnerPaymentTransfer` linked to the original customer `SalePayment`.
   - Unrelated owner `Sale` (`330,000.00`) remains untouched.
5. **Expected Receipt Lines**:
   - Remaining items on the source sale display their true commercial price (e.g., SIDU paper at `45,000.00`, printer at `2,225,000.00`, Logitech at `330,000.00`).

### 2.3 Explicit Audited Apply (`--apply`)
Applies the repair for a single reviewed transaction under deterministic hierarchical row locks. Requires:
- `--setting=<setting_id>`
- `--transaction=<id_or_code>` (exactly one transaction when `--fingerprint` is provided)
- `--apply`
- `--actor=<user_id>` (valid existing `users.id`)
- `--reason="<non-empty audit explanation>"`
- `--fingerprint=<64-char sha256 from preview>`

```bash
php artisan pos:repair-replacement-accounting \
  --setting=<setting_id> \
  --transaction=10717 \
  --apply \
  --actor=<admin_user_id> \
  --reason="Repair incident 10717 cross-owner replacement accounting duplication" \
  --fingerprint=<reviewed_sha256_fingerprint>
```

---

## 3. What Apply Modifies vs. What Apply Preserves

### 3.1 Atomic Accounting Corrections Performed
Within a single database transaction under `SELECT ... FOR UPDATE` locks (`pos_transactions` -> `pos_checkouts` -> `pos_returns` -> `sales` -> `sale_payments` -> `pos_owner_payment_transfers`):
1. **Fingerprint Revalidation**: Re-runs `reconcile()` under lock and aborts if the live `evidence_fingerprint` differs from `--fingerprint`.
2. **Synthetic Payment Invalidation**: Marks proven synthetic `CROSS-OWNER-REPL/<return_ref>` `SalePayment` rows on the replacement `Sale` as `INVALIDATED` with `invalidated_by = <actor>`, `invalidated_at = now()`, and `invalidation_source = 'POS_REPLACEMENT_ACCOUNTING_REPAIR'`.
3. **Owner Payment Allocation Transfer**: Inserts `PosOwnerPaymentTransfer` rows with `transfer_source = 'HISTORICAL_REPAIR'`, linking `source_sale_id`, `replacement_sale_id`, `original_sale_payment_id`, `invalidated_synthetic_payment_id`, `sale_return_detail_id`, and `execution_key`.
4. **Sale Header Correction**: Updates `total_amount`, `paid_amount`, `due_amount`, and `payment_status` on the affected `Sale` headers so each owner's header matches its authoritative remaining commercial charge and effective paid allocation.
5. **In-Transaction Projection Verification**: Runs `PosSettlementProjectionService::project()` and `PosReceiptReprintProjectionService::getCompletedReceiptData()` before committing; if either throws or disagrees with the proposed totals, the entire transaction rolls back.
6. **Audit Trail**: Inserts a `PosReplacementAccountingRepairAudit` record with `before_values`, `after_values`, `evidence_fingerprint`, `source_sale_ids`, `replacement_sale_ids`, `original_payment_ids`, `invalidated_payment_ids`, and `transfer_ids`.

### 3.2 Strictly Preserved Historical & Physical Facts
- **Original Customer `SalePayment` Rows**: Never deleted, invalidated, or amount-edited. Original `amount`, `date`, `reference`, `payment_method`, and attachments remain intact.
- **`SaleDetails` & `SaleBundleItem` Rows**: Quantities, selling prices (`price`, `unit_price`), and `sub_total` values are never mutated by repair.
- **`PosCheckout` & `PosTransaction` Snapshots**: Original checkout `subtotal`, `grand_total`, `paid_total`, and `PosTransactionLine` snapshots remain immutable so `?historical=1` receipts continue to show original checkout state.
- **`PosReturn` & `SaleReturn` Records**: Return status, `total_amount`, and `SaleReturnDetail` quantities/subtotals remain unchanged.
- **Physical Inventory, HPP/Cost, and Serial Lineage**: `ProductStock`, `Dispatch`, `DispatchDetail`, `ProductSerialNumber`, `SalesOrderSerialTracking`, and `SerialNumberHistory` are never replayed or modified.

### 3.3 Chains and Later Refunds
- **Chained replacements (A to B to C)** are replayed chronologically. A later hop is backed by the allocation planned into its source earlier in the chain; consumed funds are never reused, and intermediate headers are validated only after the whole chain is replayed.
- **Later cash refunds** of an overpaid replacement (live lifecycle, not this command) trim only active-backed transfers, reduce every predecessor hop and only the specific root backing payment, and never touch sibling payments. Each reduction or removal is first recorded in `pos_owner_payment_transfer_reversals` (snapshot, before/after minor amounts, actor, sale return, deleted flag). Inspect this table, keyed by `transfer_id` and `sale_return_id`, when reconciling owner balances.

---

## 4. Blocking Conditions (`[BLOCKED]`)

The planner and command refuse to apply repairs when any of the following conditions hold:
- **Missing or Mismatched Business Scope**: `--setting` is missing or does not match `pos_transactions.setting_id` and `pos_checkouts.setting_id`.
- **Non-Completed Transaction**: `pos_transactions.status` is not `COMPLETED` or checkout is missing.
- **Active / In-Flight Returns**: Any `PosReturn` on the checkout group is in a non-terminal status (`DRAFT`, `PENDING_APPROVAL`, `AWAITING_RECEIVING`, `AWAITING_SETTLEMENT`, `AWAITING_DISPATCH`).
- **Missing Replacement Lineage**: No completed cross-owner replacement detail exists, or the replacement `Sale` cannot be uniquely resolved via dispatch lineage (`dispatch_details.pos_return_line_id`) or return provenance note.
- **Ambiguous Payment Provenance**:
  - Source `Sale` lacks sufficient active customer `SalePayment` (or incoming transfer) value to back the transferred paid allocation.
  - Replacement `Sale` has both a synthetic `CROSS-OWNER-REPL/` payment and unexplained additional active payments.
  - Synthetic `CROSS-OWNER-REPL/` payment amount disagrees with the proven replacement transfer charge.
- **Unexplained / Conflicting Header Edits**: After subtracting proven cross-owner replacement transfers, a `Sale` header (`total_amount`) still disagrees with its active `SaleDetails` + `SaleBundleItem` subtotals (adjusted for `discount_amount`, `shipping_amount`, and `tax_amount`).

---

## 5. Rollback & Forward-Correction Guidance

1. **Automatic Rollback on Apply Failure**:
   If fingerprint verification, row locking, transfer creation, header update, or post-repair receipt/settlement verification fails during `--apply`, the database transaction rolls back completely with zero partial writes.
2. **Deadlock Retry**: `apply` retries up to 3 times if MySQL chooses it as a deadlock victim (e.g. a refund executing on the same checkout group). Each attempt re-locks and revalidates the fingerprint; if the evidence changed, apply refuses and a fresh preview/review is required.
3. **Idempotent Reruns**:
   Re-running preview or apply on an already repaired checkout group classifies the transaction as `[UNCHANGED]` and creates no duplicate `PosOwnerPaymentTransfer` or `PosReplacementAccountingRepairAudit` rows.
4. **Post-Commit Forward Correction**:
   - Do **not** drop `pos_owner_payment_transfers`, `pos_owner_payment_transfer_reversals`, or `pos_replacement_accounting_repair_audits` tables or deploy code that ignores transfer allocations once transfers are committed.
   - If a committed historical repair ever requires administrative adjustment, inspect the `before_values` and `after_values` JSON in `pos_replacement_accounting_repair_audits` (keyed by `pos_transaction_id` and `evidence_fingerprint`), verify no subsequent customer payments or returns were posted after `created_at`, and perform a documented, audited forward correction.
