# Purchase Settlement Implementation Logic

This document outlines the detailed logical steps and business rules for implementing the user-requested Purchase Settlement options in the ERP system.

## 1. Perbaikan/Penggantian Produk (Product Repair/Replacement)

**Goal:** Handle returns where the physical item is either repaired (same unit returned) or replaced (new unit provided).

### Logic Flow
1.  **Status Validation:** Ensure the Item Settlement status is `APPROVED_AWAITING_RECEIVE`.
2.  **Input Validation:**
    *   **Serial Number Items:**
        *   If `replacement_serial_number` input == `original_serial_number`: Treat as **Repair**.
        *   If `replacement_serial_number` input != `original_serial_number`: Treat as **Replacement**.
3.  **Execution (Repair - Same Serial):**
    *   Check `ProductSerialNumber` table for the specific ID.
    *   Update status to `AVAILABLE` (Available for sale).
    *   Set `is_in_return_process` to `false`.
    *   Update `location_id` to the receiving location.
4.  **Execution (Replacement - Different Serial):**
    *   **Identify Old Serial Record:** Find `ProductSerialNumber` by the original ID.
    *   **Update Operation:**
        *   Update key field: `serial_number` = `<new_serial_number_string>`.
        *   Update status: `AVAILABLE`.
        *   Set `is_in_return_process`: `false`.
        *   Update `location_id`: receiving location.
        *   *Note: We update the existing record to maintain lineage, effectively swapping it in place.*
    *   **Uniqueness Check:** Ensure the new serial string does not already exist for this product (except for the record being updated).

## 2. Kembali Barang Rusak (Return Damaged Goods)

**Goal:** Receive items back into stock but classify them as damaged/broken.

### Logic Flow
1.  **Check Source Status:** Determine if the item was originally sold/sent as "Good" or "Broken".
    *   *Note: Usually sales are for Good stock. Returns might be entered as 'Damaged'.*
2.  **Stock Adjustment:**
    *   **If Returning into Good Stock:** Transform quantity to `broken_quantity` in `ProductStock`.
    *   **If Returning into Broken Stock:** Increment `broken_quantity` directly.
3.  **Serial Number Handling:**
    *   Update `ProductSerialNumber` status to `BROKEN`.
    *   Set `is_broken` = `true`.
    *   Set `location_id` to receiving location.

## 3. Ubah Nota Pembelian (Modify Purchase)

**Goal:** Adjust the original Purchase document to reflect the return, effectively "un-buying" the item.

### Phase A: Target Purchase Selection
When the user chooses "Modify Purchase", the system must determine *which* Purchase Note to modify.

*   **For Serial Number Items:**
    *   **Logic:** Automatic Selection.
    *   Find `ProductSerialNumber` -> Get `ReceivedNoteDetail` -> Get `PurchaseDetail` -> Get `Purchase`.
    *   **Constraint:** The target must be the specific Purchase linked to that exact Serial Number history.
*   **For Non-Serial Number Items:**
    *   **Logic:** Filtered List Selection.
    *   Query `Purchase` records that meet **ALL** criteria:
        1.  **Supplier:** Matches the Supplier of the Return.
        2.  **Status:** Is `RECEIVED` or `RECEIVED PARTIALLY` (Active, not Draft/Void).
        3.  **Product Content:** Contains the detailed Product ID.
        4.  **Quantity Check:** The Purchase Detail Quantity for that product >= Return Quantity.
        5.  **Payment Status:** Can be Unpaid, Partial, or Paid.
    *   *UI:* User selects one from the filtered list.

### Phase B: Modification Execution

Once the target Purchase is identified:

#### Context 1: Not Yet Paid (Payment Status = Unpaid)
1.  **Reduce Quantity:**
    *   Decrement `PurchaseDetail` quantity by Return Quantity.
    *   Recalculate `sub_total`, `tax`, `discount` for the line item.
    *   Recalculate Purchase Header Totals (`total_amount`, `due_amount`).
    *   *Note: Since it's unpaid, `due_amount` simply drops.*
2.  **Archival Check:**
    *   Check specific `Purchase` document.
    *   Sum `quantity` of ALL `PurchaseDetails`.
    *   **If Total Quantity == 0:**
        *   **Action:** Archive the Purchase.
        *   Set `archived_at` = `now()`.
        *   Set `archived_by` = `auth()->user()->id`.
        *   Status = `RETURNED` (or keep existing but archived).

#### Context 2: Already Paid or Partially Paid
*Condition: This flow applies even if `Unpaid Amount` < `Return Amount`.*

1.  **Reset Payments (Data Loss Acknowledged):**
    *   **Warning:** This creates a discrepancy if not handled carefully, but per requirement:
    *   Delete (or Archive/Void) **ALL** `PurchasePayment` records associated with this Purchase.
    *   Reset `paid_amount` = 0.
2.  **Reduce Quantity:**
    *   Decrement `PurchaseDetail` quantity by Return Quantity.
    *   Recalculate line items and header totals.
3.  **Archival Check:**
    *   **If Total Quantity == 0:**
        *   **Action:** Archive the Purchase.
        *   Set `archived_at` = `now()`.
        *   Set `archived_by` = `auth()->user()->id`.
    *   **If Total Quantity > 0:**
        *   Re-evaluate Payment Status.
        *   If `paid_amount` (0) < `total_amount`, Status = `Unpaid`. (User effectively needs to re-enter valid payments if any remain).

## 4. Simpan Sebagai DP (Save as DP)

**Goal:** Treat the return value as a deposit/credit applied to a *different* specific purchase note.

### Phase A: Visibility Logic (When to show this option?)

The option "Simpan Sebagai DP" should **ONLY** appear in the UI under specific conditions:

*   **Scenario 1: Serial Number Item**
    *   **Condition A (Source):** The Purchase associated with this specific Serial Number is **Fully Paid**.
    *   **Condition B (Target Availability):** There exists **at least one other** Purchase from the **same Supplier** that is **Not Fully Paid** (Status `Unpaid` or `Partial`).
*   **Scenario 2: Non-Serial Number Item**
    *   **Condition A (Source):** There is **NO** Purchase from this Supplier that is **Not Fully Paid** containing this Product with Sufficient Quantity.
        *   *Rationale:* If such a purchase existed, the user should have used "Modify Purchase" on it. Since one doesn't exist (all relevant notes are paid), we allow "Save as DP" to apply credit elsewhere.
    *   **Condition B (Target Availability):** There exists **at least one other** Purchase from the **same Supplier** that is **Not Fully Paid**.

### Phase B: Execution Logic
1.  **Select Target:** User selects a target `Purchase` (Unpaid/Partial) from the same Supplier.
2.  **Credit Creation:**
    *   Create `SupplierCredit` record for the Return Amount.
3.  **Credit Application:**
    *   Create `PurchasePayment` for the Target Purchase.
    *   Method: `Credit` / `Deposit`.
    *   Amount: Return Amount (capped at Target Purchase Due Amount).
    *   Link to `SupplierCredit`.
4.  **Balance Handling:**
    *   If Return Amount > Target Purchase Due Amount, the remainder sits as open `SupplierCredit` for future use.
