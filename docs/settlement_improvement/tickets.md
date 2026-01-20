# Engineering Tickets: Purchase Return Settlement Improvement

## Ticket 1: Remove Cash Settlement Option (UI + Validation)
Title
- Remove cash settlement from method selection while preserving legacy display

Description
- Cash should no longer be selectable as a settlement method. Existing data must still render read-only labels without breakage.

Scope
- Settlement method dropdown should exclude CASH.
- Validation should reject new CASH submissions.
- Legacy rows with CASH must render read-only correctly in history/print views.

Technical notes
- Remove CASH from `PurchaseReturnDetail::settlementMethods()` only if legacy rendering is retained elsewhere, or filter CASH at UI and validation layers.
- Verify `PurchaseReturnSettlementForm` does not submit CASH lines.

Dependencies
- `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`
- `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php`
- `Modules/PurchasesReturn/Entities/PurchaseReturnDetail.php`
- `Modules/PurchasesReturn/Resources/views/partials/settlement-items-table.blade.php`

Edge cases
- Existing CASH settlements still visible in history.
- Older returns with CASH in print/export do not break labels.

---

## Ticket 2: Allow Paid Purchases in MODIFY_PURCHASE Selection
Title
- Expand MODIFY_PURCHASE selection to paid, unpaid, and partial purchases

Description
- Users must be able to select any relevant purchase regardless of payment status.

Scope
- Update purchase query for MODIFY_PURCHASE to include paid, unpaid, partial.
- Update UI labels for paid purchases (e.g., “Lunas”).

Technical notes
- Update `loadUnpaidPurchases()` to include paid purchases and annotate labels.
- Ensure supplier and product filters remain enforced.

Dependencies
- `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`
- `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php`

Edge cases
- Paid purchases with zero `due_amount` should still be selectable.
- Duplicate references across paid/unpaid should remain distinguishable in dropdown.

---

## Ticket 3: Quantity Mismatch Warning (Non-blocking)
Title
- Add warning when return qty exceeds purchase qty for non-serial items

Description
- Display a warning when non-serial return quantity exceeds the selected purchase quantity. Do not block submission.

Scope
- Implement warning in settlement UI after selecting a target purchase.

Technical notes
- Requires purchase detail quantity to be available in selection data.
- Warning should be client-side only; validation should not block.

Dependencies
- `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php`
- `resources/views/livewire/purchase-return/purchase-return-settlement-form.blade.php`

Edge cases
- Serial items should not show the warning.
- Purchases without quantity data should suppress warning.

---

## Ticket 4: MODIFY_PURCHASE Approval Payment Reset
Title
- Reset payments and set Unpaid status when approving Modify Purchase on paid/partial purchases

Description
- When approving Modify Purchase for a paid/partial purchase, delete all purchase payments and set payment status to Unpaid.

Scope
- Add payment cleanup after adjusting purchase totals.
- Ensure due_amount and paid_amount are normalized.

Technical notes
- Use transaction locks on purchase and payments.
- Update `PurchasesReturnSettlementController::applySettlementEffect()`.

Dependencies
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
- `Modules/Purchase/Entities/Purchase.php`
- `Modules/Purchase/Entities/PurchasePayment.php`

Edge cases
- Purchases with credit applications or external references.
- Ensure payment deletion does not break audit trails.

---

## Ticket 5: CREDIT Approval Dialog (Attachments + Notes)
Title
- Add approval dialog fields for CREDIT settlements to capture attachments and notes

Description
- Approvers should add notes and upload attachments (jpg/png/pdf) when approving CREDIT.

Scope
- Approval modal for CREDIT should include attachments + notes inputs.
- Server should validate files and save them with payment record.

Technical notes
- Reuse dropzone pattern from purchase payments.
- Ensure file size and type limits are enforced.

Dependencies
- `Modules/PurchasesReturn/Resources/views/partials/settlement-items-table.blade.php`
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
- `Modules/Purchase/Entities/PurchasePayment.php`

Edge cases
- Multiple attachments per approval.
- Partial upload failures should rollback approval.

---

## Ticket 6: Create Purchase Payment on CREDIT Approval
Title
- Create a purchase payment when CREDIT settlement is approved

Description
- CREDIT approval should generate a purchase payment for the selected purchase using settlement nominal.

Scope
- Create payment record.
- Attach files and notes from approval modal.
- Update purchase paid/due amounts.
- Link payment to supplier credit when applicable.

Technical notes
- Use `PurchasePayment` and attach media to `attachments` collection.
- Update purchase payment status based on new totals.
- Link to `PurchasePaymentCreditApplication` if supplier credit exists.

Dependencies
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`
- `Modules/Purchase/Entities/PurchasePayment.php`
- `Modules/PurchasesReturn/Entities/SupplierCredit.php`

Edge cases
- Paid purchases with due_amount = 0 should enforce nominal <= due_amount.
- Ensure supplier mismatch is blocked.

---

## Ticket 7: PRODUCT_REPAIR Receive (Serial Rules)
Title
- Enforce serial repair receive rules and replacement serial entry

Description
- For serial products, quantity is locked to 1, old serial shown, replacement serial entered.

Scope
- Lock quantity input for serial repair lines.
- Show old serial in receive modal.
- Require replacement serial input.

Technical notes
- Update receive modal for per-item settlement.
- Validate replacement serial uniqueness.

Dependencies
- `Modules/PurchasesReturn/Resources/views/partials/settlement-items-table.blade.php`
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`

Edge cases
- Replacement serial matches old serial (allowed).
- Duplicate serials across concurrent approvals.

---

## Ticket 8: Serial Lifecycle Updates on Repair/Replacement
Title
- Mark old serial returned and create new serial on repair receive

Description
- Old serial becomes permanently returned and excluded from search. Replacement serial is created as new.

Scope
- Update serial status to RETURNED and remove from active search.
- Create new serial record with active status and location.

Technical notes
- Ensure serial search components exclude RETURNED status.
- Use `ProductSerialNumber` create flow with uniqueness checks.

Dependencies
- `Modules/Product/Entities/ProductSerialNumber.php`
- `app/Livewire/AutoComplete/SerialNumberLoader.php`
- `Modules/Product/Http/Controllers/SerialNumberController.php`

Edge cases
- Reusing serial number on same product if old is RETURNED.
- Partial failures should rollback receive transaction.

---

## Ticket 9: BROKEN_STOCK Receive Quantity Lock
Title
- Lock received quantity for broken stock receive

Description
- For broken stock, receiver can only select location; quantity is read-only.

Scope
- Update receive modal to lock quantity for BROKEN_STOCK.
- Server-side validation to enforce fixed quantity.

Technical notes
- Use expected quantity from settlement item detail.
- Reject mismatched quantity server-side.

Dependencies
- `Modules/PurchasesReturn/Resources/views/partials/settlement-items-table.blade.php`
- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`

Edge cases
- Partial receives not allowed for broken stock.
- Missing location selection should block submission.
