## 1. Backend: Draft Receipt Route & Logic

- [x] 1.1 Add new route `GET /pos/transactions/{transaction}/receipt` in `Modules/Pos/Routes/web.php` with appropriate middleware (`auth`, `role.setting`, `pos.enabled`, `pos.transactions.enabled`, `can:pos.access`, `can:pos.transactions.view`).
- [x] 1.2 Implement `receipt` method in `Modules/Pos/Http/Controllers/PosTransactionController`.
    - Retrieve transaction by ID/slug, ensuring setting context match.
    - Call `PosReceiptService` to get draft data.
    - Return `pos::receipt` view with a `is_draft` flag if needed.
- [x] 1.3 Update `Modules/Pos/Services/PosReceiptService` to support `PosTransaction`.
    - Add `getTransactionReceiptData(PosTransaction $transaction)` method.
    - Map transaction lines, totals, and business info carefully.
    - Ensure payment/change info is omitted or set to zero.
- [ ] 1.4 Test draft receipt generation via direct URL access.

## 2. Frontend: Success Modal & Workflow

- [x] 2.1 Add `#pos-save-success-modal` HTML structure to `Modules/Pos/Resources/views/sell.blade.php`.
    - Design a clean Bootstrap modal with success icons and clear typography.
    - Include a placeholder for the TRX number.
    - Add buttons: `pos-save-success-continue-btn` and `pos-save-success-print-btn`.
- [x] 2.2 Refactor `pos-save-draft` event listener in `sell.blade.php`.
    - Update the success handler of the `jsonRequest(saveAndNewEndpoint, 'POST')` call.
    - Capture `response.transaction.id` and `response.transaction.code`.
    - Show `#pos-save-success-modal`, update TRX label, and set data attributes for the print button.
- [x] 2.3 Implement button actions for the modal.
    - "Lanjut" (Continue): Focus back on search input and ensure UI is ready for new items.
    - "Cetak Struk" (Print): Call a JS function to open the new draft receipt URL in a new blank tab.

## 3. Styling: Bolder Thermal Receipts

- [x] 3.1 Update CSS in `Modules/Pos/Resources/views/receipt.blade.php`.
    - Increase global `font-weight` to at least `600` for the `body`.
    - Ensure headers and item names stand out using `700+`.
    - Adjust standard thermal font families for maximum legibility.
- [x] 3.2 Add conditional "DRAFT" or "PENAWARAN" labeling in `receipt.blade.php` based on passed data/flag.
- [ ] 3.3 Verify thermal print output for clear legibility on physical/simulated printer.
