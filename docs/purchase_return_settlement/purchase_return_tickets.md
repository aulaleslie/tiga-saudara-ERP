# Purchase Return Settlement — Engineering Tickets

## Ticket 1: Settlement Entry Page (Kelola Penyelesaian)
Description
Create the settlement entry page accessed from the purchase return detail, listing all return items and serials and allowing per-item/serial settlement selection.
Scope
- Add UI entry point from purchase return detail to settlement page.
- Render items and serials with settlement selection controls.
- Save settlement draft.
Technical notes
- Page should accept purchase_return_id and load related return items and serials.
- Persist per-item/serial settlement selections in a structured payload.
Dependencies
- Purchase return detail page exists and can link to settlement.
- Return items and serial data available.
Edge cases
- Return with zero items or missing serial data.
- Large number of serials causing slow render.

## Ticket 2: Serial vs Non-serial Settlement Rules
Description
Enforce per-serial settlement for serial-tracked products and single-method-for-quantity for non-serial products.
Scope
- Detect serial-tracked products.
- Enforce one settlement method for all quantity on non-serial items.
Technical notes
- Validation layer should reject mixed methods for non-serial items.
Dependencies
- Product metadata indicates serial tracking.
Edge cases
- Item flagged as serial but missing serial entries.
- Mixed serial and non-serial items in one return.

## Ticket 3: Settlement Method — Perbaikan Produk
Description
Implement validation for "Perbaikan Produk" requiring same product, and for serial items, the same serial.
Scope
- Allow selection and save for this method.
- Validate serial matches receive ID when applicable.
Technical notes
- Store expected serial for return receipt on settlement record.
Dependencies
- Serial ↔ receive ID mapping available.
Edge cases
- Serial mismatch or missing receive ID blocks save.

## Ticket 4: Settlement Method — Kembali Barang Rusak
Description
Implement broken return settlement and update broken stock immediately upon approval.
Scope
- Allow selection and save.
- On approval, update broken stock quantities.
Technical notes
- Use inventory module’s broken stock adjustment APIs.
Dependencies
- Inventory module supports broken stock updates.
Edge cases
- Multiple items updating broken stock in one settlement.

## Ticket 5: Settlement Method — Ubah Nota Pembelian
Description
Enable settlement by offsetting an unpaid/partially-paid approved purchase, with strict validation and stock/financial updates.
Scope
- Allow selecting a target purchase.
- Validate outstanding balance >= return value per item.
- On approval, update stock and financial records.
Technical notes
- Require purchase ID + receive ID + serial for serial items.
- Ensure purchase status is unpaid or partially paid.
Dependencies
- Purchase module exposes unpaid/partially-paid status and outstanding balance.
- Financial posting logic for purchase adjustments.
Edge cases
- No eligible unpaid purchase found.
- Return value exceeds outstanding balance.
- Serial not linked to selected purchase/receive.

## Ticket 6: Settlement Method — Simpan Sebagai Kredit
Description
Allow credit settlement by applying a nominal amount to another approved unpaid/partially-paid purchase.
Scope
- Select target purchase and input nominal credit amount.
- Validate nominal > 0.
Technical notes
- Credit applied as payment against selected purchase.
Dependencies
- Purchase module supports applying payment to unpaid/partially-paid purchase.
Edge cases
- Nominal exceeds outstanding balance.
- Target purchase changes status between selection and save.

## Ticket 7: Settlement Method — Pengembalian Tunai
Description
Allow cash return with payment method selection and nominal amount.
Scope
- Select payment method and input nominal.
- Validate nominal > 0.
Technical notes
- Payment method list sourced from existing payment configuration.
Dependencies
- Payment methods available in system configuration.
Edge cases
- Payment method disabled mid-session.

## Ticket 8: Validation Rules & Blocking Save
Description
Centralize settlement validation to block invalid selections before approval.
Scope
- Validate serial and receive ID matching.
- Block negative or zero values.
- Prevent returns exceeding received quantity.
- Block cross-purchase returns for same SKU.
Technical notes
- Implement server-side validation; client-side optional.
Dependencies
- Access to receive quantities and original purchase reference.
Edge cases
- Concurrent updates to receive quantities.

## Ticket 9: Pricing & Amounts
Description
Display last purchase price and allow nominal input for settlement amounts while excluding global expenses from return value.
Scope
- Show last purchase price per item/serial.
- Allow nominal override for settlement.
Technical notes
- Use last purchase price from purchase history.
- Global expenses stored separately, not in return value calculation.
Dependencies
- Purchase price history accessible per SKU.
Edge cases
- No purchase history exists for item.

## Ticket 10: Approval & Locking
Description
Add approval step for settlement and lock edits after approval.
Scope
- Approval action and status tracking.
- Prevent edits after approval.
Technical notes
- Separate settlement approval status from purchase return status.
Dependencies
- Role/permission for approving settlement.
Edge cases
- Concurrent approvals; prevent double-approval.

## Ticket 11: Inventory & Financial Effects on Approval
Description
Apply inventory and financial effects per settlement method upon approval.
Scope
- Trigger broken stock updates for "Kembali Barang Rusak."
- Trigger stock and financial updates for "Ubah Nota Pembelian."
Technical notes
- Apply effects in a transaction to avoid partial updates.
Dependencies
- Inventory and finance services available.
Edge cases
- Partial failure in downstream services.

## Ticket 12: Permissions for Settlement Creation
Description
Ensure users with settlement creation permission can select all settlement methods including cash and credit.
Scope
- Permission check for access to settlement page and method selection.
Technical notes
- Use existing authorization policies for create settlement.
Dependencies
- Defined role/permission model.
Edge cases
- User loses permission mid-session.
