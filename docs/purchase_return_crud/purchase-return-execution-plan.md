# Purchase Return Execution Plan (Current Status)

## 1. Status Summary
- The create flow is implemented in Livewire (`PurchaseReturnCreateForm` + `PurchaseReturnTable`).
- Line-level location, serial handling, and duplicate-line validation are in place.
- Location search is filtered to positive stock with tenant-aware labels.
- Creation saves a pending document without inventory mutation.
- Approval re-validates stock and serials before status changes.
- Purchase price is hidden on the create UI but used for totals.
- Price-related columns are not yet permission-gated on list/detail views.
- Approve/reject currently relies on `purchaseReturns.edit` instead of a dedicated approval permission.

## 2. Ticket Status Overview
- Tickets 1-9: Implemented.
- Ticket 10 (purchase order auto-lock from serials): Not implemented.
- Tickets 11-12 (price-view gating and approval permission): Not implemented.

## 3. Remaining Work (Optional Enhancements)
- Decide whether to add purchase order selection and serial-to-PO locking in create.
- Decide whether to enforce quantity <= stock at create time (currently only checks stock > 0).
- Review legacy header location usage in read/settlement views if deprecation is desired.
- Add `purchaseReturns.viewPrice` gating for list/detail price fields.
- Add `purchaseReturns.approval` gating for approve/reject actions and endpoints.

## 4. Verification Checklist
- Create return with non-serial product and confirm location/stock validation.
- Create return with serial-tracked product and confirm location auto-lock and serial uniqueness.
- Approve a pending return with sufficient stock and verify approval succeeds.
- Attempt approval with insufficient stock or invalid serial status and verify it fails.
- Confirm price fields are not visible on the create form.
- Confirm list/detail price columns are hidden without `purchaseReturns.viewPrice`.
- Confirm approve/reject actions are blocked without `purchaseReturns.approval`.
