# Operational Correction Guide: Historical Bundle Component Omission (Incident TNC-TXN-2026-10-0638)

## 1. Incident Background

On transaction `TNC-TXN-2026-10-0638`, a multi-owner bundle sale was processed at checkout:
- **Parent Camera**: SONY ALPHA A7 IV CAMERA recorded under Owner A (Sale #210538, SaleDetail #423830, DispatchDetail #424501), price Rp630,000.
- **Component MicroSD**: MEMORY MICRO SD SANDISK ULTRA 32GB (C10) recorded under Owner B (Sale #210539 / `PD-JL-2026-10-00653`, Carrier Detail #423831, SaleBundleItem #900, DispatchDetail #424502), price Rp260,000.

When the customer returned the purchase under POS Return #111 (`TNC-POSRT-2610-0005`), the legacy return workflow processed only the camera parent:
- **Parent return processed**: SaleReturn #111 was created for Rp630,000, stock was received for the camera, and cash refund of Rp630,000 was settled.
- **Component omitted**: The microSD component remained recorded as dispatched (1 unit) in DispatchDetail #424502, stock at Location 6 remained decremented, and an unpaid debt of Rp260,000 remained open on Sale #210539.

This guide details the audited, evidence-gated administrative command provided to correct this omission safely.

---

## 2. Command Synopsis

The correction command is registered in Artisan as:

```bash
php artisan pos:repair-historical-bundle-omission {transaction=TNC-TXN-2026-10-0638} [options]
```

### Options:
- `--apply`: Execute the database repair. If omitted, runs in **DRY-RUN** mode (zero writes).
- `--confirm-physical-return`: Explicit confirmation that the physical component was returned. **Mandatory** when `--apply` is specified.
- `--actor=<id>`: User ID of the authorized operator executing the correction. **Mandatory** when `--apply` is specified (no silent defaults).
- `--reason="<text>"`: Audit justification logged in the corrective record and database transaction history. **Mandatory** when `--apply` is specified (no silent defaults).

---

## 3. Dry-Run Discovery Mode

Always run in dry-run mode first to verify candidate evidence:

```bash
php artisan pos:repair-historical-bundle-omission TNC-TXN-2026-10-0638
```

### Expected Output:
```text
Mode: DRY-RUN (no data changed)

Status: [CORRECTABLE]

Source Evidence Lineage:
  Transaction: TNC-TXN-2026-10-0638 (ID: 7088)
  POS Return: #111 (TNC-POSRT-2610-0005)
  Parent Sale Return: #111 (Sale ID: 210538)
  Component Sale: #210539 (PD-JL-2026-10-00653)
  Carrier Sale Detail: #423831
  Sale Bundle Item: #900
  Dispatch Detail: #424502

Missing Component Information:
  Product: #513 - MEMORY MICRO SD SANDISK ULTRA 32GB (C10) (SKU-15C74072)
  Location: #6 | Owner Setting: #6 | Stock Bucket: quantity_non_tax
  Quantity: 1 | Unit Price: Rp 260,000.00 | Subtotal: Rp 260,000.00
  Unit Cost: Rp 162,719.24 | Total Cost: Rp 162,719.24

Commercial & Monetary Effects:
  Debt Reduction: Rp 260,000.00
  Cash Refund: Rp 0.00
...
```

Exit code: `0` for correctable or already corrected, `2` for conflict or ambiguous evidence.

---

## 4. Apply Prerequisites

Before executing with `--apply`, verify the following operational requirements:
1. **Physical Return Confirmation**: Confirm with store/warehouse staff at Location 6 that the microSD card (32GB) was received physically.
2. **Authorized Actor**: Identify the administrator or manager running the correction (e.g., user ID `1`).
3. **Audit Reason**: Formulate a concise justification (e.g., `"Administrative repair of omitted bundle component microSD for incident TNC-TXN-2026-10-0638"`).

---

## 5. Execution Workflow (Apply)

To execute the atomic repair:

```bash
php artisan pos:repair-historical-bundle-omission TNC-TXN-2026-10-0638 \
  --apply \
  --confirm-physical-return \
  --actor=1 \
  --reason="Administrative repair of omitted bundle component microSD for incident TNC-TXN-2026-10-0638"
```

### What Apply Executes:
1. **Acquires Pessimistic Locks First**: Locks PosTransaction, PosReturn, PosCheckoutSales, Sale #210539, Carrier Detail #423831, SaleBundleItem #900, DispatchDetail #424502, Product #513, and ProductStock *before* evaluating candidate state.
2. **Re-verifies Invariants Under Lock**: Re-evaluates incident target matching, enforces exact 1-unit quantity, ensures zero customer payment on component sale (`paid_amount == 0.0`), rejects intentional parent-only returns, and aborts immediately on any state change or conflict.
3. **Creates Linked Corrective SaleReturn**:
   - Reference: `TNC-SLRN-CORR-{yymm}-{pos_return_id}`.
   - Linked to `pos_return_id = 111` and `sale_id = 210539`.
   - Total amount: Rp 260,000.00, Paid: Rp 0.00, Due: Rp 0.00, Status: `COMPLETED`, Payment Status: `PAID`.
   - Notes include defect version tag `pos-historical-bundle-omission-v1` and audit reason.
4. **Creates Corrective SaleReturnDetail**:
   - Quantity: 1, Subtotal: Rp 260,000.00.
   - Preserves cost snapshot: Rp 162,719.24 (`cost_origin = 'bundle_item'`).
   - Injects audit payload in `execution_context`.
5. **Receives Stock into Owner Bucket**:
   - Location 6 is Non-PKP; stock is added directly to `quantity_non_tax` (+1) and global product quantity (+1).
   - Inserts audit ledger row in `transactions` table (`type = 'SALE_RETURN_GOOD_NON_TAX'`).
6. **Decrements Dispatch Detail**:
   - Dispatched quantity on DispatchDetail #424502 is decremented from 1 to 0.
7. **Clears Commercial Values & Debt**:
   - SaleBundleItem #900: quantity set to 0, subtotal to 0.
   - Sale #210539: total amount set to Rp 0.00, due amount cleared from Rp 260,000.00 to Rp 0.00.
   - Zero cash refund issued.
8. **Archives Source Sale**:
   - Marks Sale #210539 status as `RETURNED`, sets `archived_at` and `archived_by`.
9. **Untouched Camera Reversal**:
   - Parent camera records (SaleReturn #111, Sale #210538) are not modified or replayed.

---

## 6. Safety Guarantees & Idempotency

- **Idempotent Retry**: If the command is run again after repair, discovery classifies the state as `[ALREADY_CORRECTED]` and exits cleanly with code `0`. No duplicate `SaleReturn`, no double stock increments, and no duplicate ledger records are created.
- **Rollback on Error**: Any uncaught exception inside the database transaction triggers a full rollback, restoring all rows to their pre-execution state.
- **No Cash Payout**: Explicitly enforces `cash_refund = 0.00`. The customer did not pay cash for the component sale; clearing the debt resolves the balance without cash leakage.

---

## 7. Post-Execution Audit Verification Checklist

After running the command with `--apply`, execute the following checks to confirm data integrity:

| Check | Verification Target | Expected Value |
|---|---|---|
| **SaleReturn** | `SaleReturn::where('pos_return_id', 111)->where('reference', 'like', '%CORR%')->first()` | `status = 'COMPLETED'`, `total_amount = 260000`, `paid_amount = 0`, `due_amount = 0` |
| **Stock Bucket** | `ProductStock::where('product_id', 513)->where('location_id', 6)->first()` | `quantity_non_tax` incremented by 1 |
| **Stock Ledger** | `DB::table('transactions')->where('product_id', 513)->latest()->first()` | `type = 'SALE_RETURN_GOOD_NON_TAX'`, `quantity = 1` |
| **Dispatch Detail** | `DispatchDetail::find(424502)->dispatched_quantity` | `0` |
| **Sale Debt** | `Sale::withArchived()->find(210539)->due_amount` | `0.00` (Status: `RETURNED`, `archived_at` populated) |
| **Camera Return** | `SaleReturn::find(111)` | Untouched, `total_amount = 630000`, `paid_amount = 630000` |
