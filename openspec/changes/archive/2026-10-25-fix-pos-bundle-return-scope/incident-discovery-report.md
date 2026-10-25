# Incident TNC-TXN-2026-10-0638: Read-Only Discovery Report

**Generated At:** 2026-10-22  
**Command Executed:** `php artisan pos:repair-historical-bundle-omission TNC-TXN-2026-10-0638`  
**Execution Mode:** DRY-RUN (Read-Only, 0 database writes)  
**Status:** `[CORRECTABLE]`  

---

## 1. Summary of Discovery Findings

The local production-mirrored dataset was evaluated against the evidence-gating rules defined in `PosHistoricalBundleCorrectionService`. All target records for incident `TNC-TXN-2026-10-0638` were located with exact matching identities.

- **Primary Incident Identifiers**:
  - POS Transaction: `TNC-TXN-2026-10-0638` (Internal ID: `7088`)
  - POS Return: `#111` (Reference: `TNC-POSRT-2610-0005`, Status: `COMPLETED`)
  - Parent Sale: `#210538` (Parent Sale Return: `#111`, Amount: `Rp 630,000.00`)
- **Omitted Component Identifiers**:
  - Component Sale: `#210539` (Reference: `PD-JL-2026-10-00653`)
  - Component Product: `#513` - `MEMORY MICRO SD SANDISK ULTRA 32GB (C10)` (SKU: `SKU-15C74072`)
  - Carrier Sale Detail: `#423831` (Quantity: 0, Subtotal: `Rp 260,000.00`)
  - Sale Bundle Item: `#900` (Quantity: 1, Subtotal: `Rp 260,000.00`)
  - Dispatch Detail: `#424502` (Dispatched Quantity: 1)
  - Stock Location: `#6` | Owner Setting: `#6` (Non-PKP Location)
  - Target Stock Bucket: `quantity_non_tax`
  - Cost Snapshot: `Rp 162,719.24` (`cost_origin = 'bundle_item'`)

---

## 2. Quantitative Evidence & Impact Comparison

### Commercial & Monetary Values
- **Customer Debt Reduction**: `Rp 260,000.00` (clearing unpaid balance on Sale #210539)
- **Cash Refund to Customer**: `Rp 0.00` (Customer did not pay cash for the separate component sale; cash refund for the parent camera of `Rp 630,000.00` was already settled under SaleReturn #111)

### Stock Impact Comparison
| Metric | Current Value | Expected After Correction | Delta |
|---|---|---|---|
| Global Product Quantity (Product #513) | 46 | 47 | +1 |
| Location Stock Quantity (Location #6) | 41 | 42 | +1 |
| Bucket (`quantity_non_tax` at Location #6) | 41 | 42 | +1 |

### Dispatch Detail Comparison
| Record | Current Dispatched Quantity | Expected After Correction | Delta |
|---|---|---|---|
| Dispatch Detail #424502 | 1 | 0 | -1 |

### Component Sale Comparison
| Field | Current State | Expected After Correction | Note |
|---|---|---|---|
| Total Amount | Rp 260,000.00 | Rp 0.00 | Reversed commercial line |
| Paid Amount | Rp 0.00 | Rp 0.00 | Unpaid internal split |
| Due Amount | Rp 260,000.00 | Rp 0.00 | Erroneous debt cleared |
| Payment Status | UNPAID | PAID | Reconciled balance |
| Sale Status | DISPATCHED | RETURNED | Ready for archival |

---

## 3. Evidence Integrity Verification

- [x] **Parent Camera Return Verified**: SaleReturn #111 exists, is `COMPLETED`, and covers Parent Sale #210538 for Rp630,000.
- [x] **No Component Returned in Original**: Neither SaleBundleItem #900 nor DispatchDetail #424502 is referenced in any SaleReturnDetail linked to POS Return #111.
- [x] **Unrepaired / Untampered State**: DispatchDetail #424502 dispatched quantity is still `1`, SaleBundleItem #900 quantity is `1`, and Sale #210539 due amount is `Rp 260,000.00`.
- [x] **Zero Writes Confirmed**: Discovery executed strictly read-only queries with 0 database modifications.

---

## 4. Next Step Recommendation

The incident is classified as **`[CORRECTABLE]`**.

When authorized operators confirm that the physical 32GB MicroSD card was returned, execute the audited correction with:

```bash
php artisan pos:repair-historical-bundle-omission TNC-TXN-2026-10-0638 \
  --apply \
  --confirm-physical-return \
  --actor=1 \
  --reason="Administrative repair of omitted bundle component microSD for incident TNC-TXN-2026-10-0638"
```
