# POS Return Balance Repair Command: Operational Guide & Procedures

## 1. Overview & Purpose

`pos:repair-return-balances` is an administrative CLI command designed to safely repair proven historical POS return header and balance drift in completed transactions (such as incident transaction 9218) without mutating stored detail rows, payment records, or completed return history.

### Historical Defect Context
In historical POS cash return workflows, recalculation reconstructed sale headers by summing rounded unit prices multiplied by line quantities. When lines possessed fractional unit prices (for instance, 120 whiteboard markers priced at a blended Rp6,583.33 with stored subtotal Rp790,000.00), the multiplication produced Rp789,999.60, creating a Rp0.40 header shortage.

Because the reprint service (`PosReceiptReprintProjectionService`) allocates sale headers proportionally across commercial rows, this Rp0.40 header drift redistributed across multiple printed rows, producing unexpected decimal cents on printed receipts and an inaccurate outstanding debt balance, even though the underlying stored sale rows already totaled clean amounts (e.g., Rp30,105,000 across the three owner sales of transaction 9218).

---

## 2. Command Usage

### 2.1 Preview Mode (Default / Dry-Run)
The command defaults to read-only preview. It performs zero writes to the database, leaving all monetary and audit records untouched.

```bash
php artisan pos:repair-return-balances --setting=<setting_id> --transaction=<transaction_id_or_code>
```

#### Repeatable Explicit Transactions:
Multiple explicit transactions can be previewed or processed together:
```bash
php artisan pos:repair-return-balances --setting=1 --transaction=9218 --transaction=9219
```

#### Example Output:
```text
POS Return Balance Repair | Business Setting: #1 (Toko Tiga Saudara) | Mode: DRY-RUN PREVIEW (No Changes) | Candidates: 1

----------------------------------------------------------------------
Evaluating POS Transaction: 9218
Classification: [REPAIRABLE]
Evidence Fingerprint: 4f9a7b2c8e1d...

+--------------------+------------------+----------------+-----------------+
| Metric             | Captured/Stored  | Current Header | Proposed Header |
+--------------------+------------------+----------------+-----------------+
| Original Charges   | 31,305,000.00    | -              | -               |
| Committed Returns  | 1,200,000.00     | -              | -               |
| Stored Detail Rows | 30,105,000.00    | -              | -               |
| Grand Total        | -                | 30,104,999.60  | 30,105,000.00   |
| Paid Amount        | -                | 375,000.00     | 375,000.00      |
| Due Balance        | -                | 29,729,999.60  | 29,730,000.00   |
+--------------------+------------------+----------------+-----------------+

Reachable Sales:
+--------+-----------+--------+-----------+---------------+---------------+------------+---------------+--------------+
| Sale # | Reference | Drift? | Drift Amt | Current Tot   | Proposed Tot  | Paid       | Current Due   | Proposed Due |
+--------+-----------+--------+-----------+---------------+---------------+------------+---------------+--------------+
| 101    | SO-001    | NO     | 0.00      | 3,840,000.00  | 3,840,000.00  | 0.00       | 3,840,000.00  | 3,840,000.00 |
| 102    | SO-002    | NO     | 0.00      | 4,300,000.00  | 4,300,000.00  | 0.00       | 4,300,000.00  | 4,300,000.00 |
| 103    | SO-003    | YES    | 0.40      | 21,964,999.60 | 21,965,000.00 | 375,000.00 | 21,589,999.60 | 21,590,000.00|
+--------+-----------+--------+-----------+---------------+---------------+------------+---------------+--------------+

Completed Cash Returns:
+----------+------------+-------------+-----------+--------------+
| Return # | Reference  | Option      | Status    | Total Return |
+----------+------------+-------------+-----------+--------------+
| 55       | RET-0055   | cash_return | completed | 1,200,000.00 |
+----------+------------+-------------+-----------+--------------+
======================================================================
Preview completed successfully.
```

### 2.2 Apply Mode (Audited Execution)
Requires `--apply`, `--actor=<user_id>`, and `--reason="<text>"`.
Optionally accepts `--expected-fingerprint=<sha256>` to guarantee the target state has not changed since preview.

```bash
php artisan pos:repair-return-balances \
  --setting=1 \
  --transaction=9218 \
  --apply \
  --actor=1 \
  --reason="Repair proven Rp0.40 historical return header shortage" \
  --expected-fingerprint=4f9a7b2c8e1d...
```

---

## 3. Supported Evidence & Reconciliation Logic

The repair command evaluates the whole checkout group in integer minor units (cents):

1. **Original Captured Owner Charges**: Extracted from `pos_checkout_sales.grand_total` (or checkout grand total for single-owner checkouts).
2. **Completed Committed Returns**: Loaded from completed, non-reversed cash returns (`pos_returns` and `sale_returns.total_amount`). All `SaleReturn` records on the reachable sales must correspond to the loaded `PosReturn` entities; unlinked, non-cash, or rejected returns cause immediate blocking.
3. **Stored Remaining Detail Rows**: Computed from active `sale_details.sub_total` plus supplemental bundle items.
4. **Row-Level Quantity and Value Invariants**:
   Every original captured transaction line (`PosTransactionLine`) is verified against its mapped stored sale detail (`SaleDetails`) and returned lines (`PosReturnLine`):
   $$\text{Original Quantity} = \text{Remaining Quantity} + \text{Returned Quantity}$$
   $$\text{Original Minor Units} = \text{Remaining Subtotal Minor Units} + \text{Returned Minor Units}$$
   Offsetting discrepancies between rows are strictly prohibited. Proven blended-unit-price rounding drift is permitted only when mathematically validated:
   $$\text{round}\left(\frac{\text{accounted\_cents}}{100 \times \text{orig\_qty}}, 2\right) = \text{txn\_unit\_price}$$
5. **Aggregate Reconciliation Invariant**:
   $$\text{Expected Remaining} = \text{Captured Charge} - \text{Committed Cash Returns} = \sum \text{Stored Remaining Rows}$$
   If captured charge less committed returns does not equal the stored detail rows, the command refuses to repair and reports a block reason.
6. **Historical Unit-Price Reconstruction Verification**:
   A header mismatch is accepted only when the drift is proven by reconstructing the header from rounded unit prices:
   $$\text{Current Header} = \sum \text{round}(\text{round}(\text{unit\_price}, 2) \times \text{qty}, 2)$$
   Arbitrary discrepancies or unexplained differences are strictly blocked.
7. **Receipt Reprint Projection Validation**:
   The repair executes `PosReceiptReprintProjectionService::getCompletedReprintReceiptData` in read-only preview, and re-executes it under database lock inside the correction transaction. If receipt reprint projection fails, is ambiguous, or mismatches expected totals and debt, the candidate is blocked or the transaction is rolled back.

---

## 4. Block Reasons & Guardrails

The command refuses to mutate and reports `[BLOCKED]` when:
- **Missing or Invalid Business Scope**: `--setting` is omitted or does not match the POS transaction's owning business setting.
- **Incomplete / Non-Completed Transaction**: The transaction is in `DRAFT` status or not posted.
- **Missing Checkout Mapping**: Checkout record or reachable sales mapping is missing or invalid.
- **Active Overlapping Returns**: A return on the checkout is in `draft`, `pending_approval`, `awaiting_receiving`, `awaiting_settlement`, or `awaiting_dispatch`.
- **Unsupported Return Options**: The checkout has non-cash returns (e.g. product replacement) or unlinked/rejected `SaleReturn` records.
- **Row-Level Discrepancies**: Captured original rows do not match remaining plus returned quantities/amounts, or offsetting row errors exist.
- **Receipt Projection Failure**: `getCompletedReprintReceiptData` fails, is ambiguous, or diverges from expected grand total or debt.
- **Unexplained Header Adjustments**: The sale carries invoice-level discounts (`discount_amount > 0`) or shipping adjustments (`shipping_amount > 0`).
- **Overpayments**: Recorded `paid_amount` exceeds the remaining total.
- **Unexplained Header Discrepancy**: The difference between the stored rows and current header is not proven by historical unit-price reconstruction.

---

## 5. Distinction Between Stored Rows and Projected Receipt Rows

| Dimension | Stored Detail Rows (`sale_details`) | Projected Receipt Rows (`PosReceiptReprintProjectionService`) |
| :--- | :--- | :--- |
| **Data Source** | Database table `sale_details` (sub_total, unit_price, quantity) | Dynamic in-memory projection combining checkout lines and sale headers |
| **Incident 9218 State** | Completely accurate (stored 790,000 for markers, 30,105,000 total) | Skewed by proportional header allocation (spread 0.40 deficit into decimals) |
| **Repair Action** | **Preserved untouched** (never edited by repair) | **Corrected automatically** once header equals 21,965,000 |
| **Fully Returned Items** | Maintained at quantity 0 in active rows | Omitted from current receipt rows; displayed in return history |
| **Precision** | Authoritative decimal values preserved as recorded | Evaluated against Rp100 receipt multiples for standard commercial lines |

---

## 6. Concurrency, Locking & Audit Trail

- **Deterministic Lock Hierarchy**: Under a single database transaction, entities are locked with `lockForUpdate()` in ascending ID order:
  1. `pos_transactions` (`id ASC`)
  2. `pos_checkouts` (`id ASC`)
  3. `pos_returns` (`id ASC`)
  4. `sales` (`id ASC`)
- **State Re-verification**: State is re-reconciled and fingerprints re-checked under lock before mutation.
- **In-Transaction Receipt Verification**: Fresh receipt reprint and settlement projections are executed against the modified models inside the transaction. If projected total or live due balance differs from the proposed state, the transaction rolls back immediately.
- **Audit Persistence**: Every applied correction inserts an audit record in `pos_return_balance_repair_audits` capturing before/after values, evidence fingerprint, actor ID, and reason.
- **No-Op Idempotency**: Running apply on an already repaired transaction results in `[UNCHANGED]` with zero writes and zero duplicate audit entries.
- **Testing Safety Rule**: Implementation and automated testing must never target or mutate live transaction 9218 in production databases; all verification utilizes synthetic fixtures.

---

## 7. Operational Review & Evidence-Based Rollback Limits

### 7.1 Pre-Execution Operator Checklist
Before applying a repair in an operational or production environment, operators must execute the following checklist:
1. **Scope Verification**: Confirm the business setting ID (`--setting=<id>`) corresponds to the business owning the target POS transaction.
2. **Read-Only Preview**: Always execute dry-run preview first:
   ```bash
   php artisan pos:repair-return-balances --setting=1 --transaction=9218
   ```
3. **Evidence Fingerprint Recording**: Note the exact `evidence_fingerprint` output from the preview.
4. **Classification Confirmation**: Verify the classification is `[REPAIRABLE]`. If `[BLOCKED]`, inspect the blocking reason and do not attempt forced modification.
5. **Monetary Sanity Check**: Confirm that stored row totals, original captured charges, and committed returns agree with historical sales documentation.
6. **No Active Cashier Session**: Ensure cashier terminals are not actively modifying or processing returns/payments on the targeted checkout.

### 7.2 Evidence-Based Rollback Limits
If an operational rollback is required following an applied repair:
- **Audit Traceability**: Locate the relevant audit record in `pos_return_balance_repair_audits` by `pos_transaction_id` or `sale_id`. The record contains exact `before_values` (`total_amount`, `paid_amount`, `due_amount`, `payment_status`).
- **No Blind Reversals**: A balance restoration must **never** be performed blindly if subsequent transactions, payments, or returns have occurred on that sale. The operator must verify that no subsequent `sale_payments` or `pos_returns` were created after the audit timestamp.
- **Transactional Rollback Behavior**:
  - If any precondition fails during apply (e.g. evidence fingerprint mismatch, lock acquisition timeout, or receipt settlement projection mismatch), the entire database transaction rolls back automatically with zero partial writes.
  - No audit record is committed if the transaction rolls back.

### 7.3 Protection of Live Transaction 9218
- Under no circumstances should test suites, continuous integration pipelines, or local development environments point to or execute mutating `--apply` operations against live transaction 9218 in production databases.
- All testing, verification, and regression tests use ephemeral, synthetic fixtures created via SQLite in-memory or test databases with `RefreshDatabase`.
- Application to live production transaction 9218 requires deliberate, authorized administrative execution with authenticated user credentials passed to `--actor` and documented operational justification passed to `--reason`.

