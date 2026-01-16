# Purchase Return Settlement — Requirements Document

## 1. Overview
Refactor Purchase Return settlement to be per item. For serial-tracked products, settlement is per serial; for non-serial products, one settlement method applies to the full quantity. Settlement is configured on the "Kelola Penyelesaian" page after supplier communication, then approved and locked.

## 2. Goals & Non-goals
Goals
- Enable per-item/serial settlement with explicit options and validations.
- Enforce data integrity (serial ↔ receive ID, unpaid purchase availability, outstanding balance).
- Apply correct stock and financial impacts per settlement type.
- Provide approval and immutability after approval.

Non-goals
- Supplier negotiation workflow or dispute resolution automation.
- New accounting policies or invoice lifecycle changes.
- Audit logging or supplier account history (for now).
- UI filtering of valid options (validation only on submit).

## 3. Personas
- Purchase/Returns Staff: create settlements.
- Warehouse/QA: decide item/serial disposition.
- Finance/Accounting: validate financial and inventory impacts.
- Purchasing Manager: approve settlements.

## 4. User Journeys
- Create Settlement: open purchase return detail → click "Kelola Penyelesaian" → choose settlement per item/serial → save.
- Approve Settlement: review → approve → settlement locks → stock/financial updates applied.
- Error Flow: invalid selection (no unpaid purchase, serial mismatch, over-limit) blocks save.

## 5. Functional Requirements
Settlement Scope
- Serial-tracked: settlement per serial.
- Non-serial: one method for total quantity; no quantity split.

Settlement Methods
- Perbaikan Produk: same product returned; for serial, specific serial expected.
- Kembali Barang Rusak: update broken stock immediately.
- Ubah Nota Pembelian: requires unpaid/partially-paid approved purchase; requires purchase ID + receive ID + serial; return value per item <= outstanding balance; updates stock and financials.
- Simpan Sebagai Kredit: select unpaid/partially-paid approved purchase and input nominal.
- Pengembalian Tunai: select payment method and nominal.

Validation Rules
- Serial number required; must match receive ID.
- Return quantity cannot exceed received quantity.
- No "Ubah Nota Pembelian" without unpaid purchase.
- No negative or zero settlement values.
- Returns cannot span multiple original purchases for the same SKU.

Pricing/Amounts
- Display last purchase price; allow nominal input for settlement.
- Shipping/discounts/taxes/restocking are global expenses and excluded from purchase return value.

Workflow/Permissions
- Settlement set on "Kelola Penyelesaian" page.
- Separate approval; after approval, settlement cannot be changed.
- Users who can create settlement can select cash and credit methods.
- No approval thresholds by amount.

## 6. Non-Functional Requirements
- Data integrity across inventory and finance modules.
- Concurrency safety: no edits after approval.
- Performance acceptable for listing items and serials.

## 7. Assumptions
- Purchase records provide unpaid/partially-paid status and outstanding balance.
- Serial numbers are reliably linked to receive IDs.
- Broken stock tracking exists in inventory.

## 8. Constraints
- No UI filtering of valid options; validation on submit only.
- No audit logs for per-serial/item decisions (for now).
- Settlement is entered after supplier agreement (manual process).

## 9. Open Questions
- How to present mixed settlement outcomes in summary (manager/finance view).
- Exact UI flow for approval and review roles beyond "create settlement."
- Formula for return value when nominal input differs from last purchase price.
