## Context

Cashiers using the POS are presented with a tier selection when adding a new customer, which is not necessary during a fast checkout process and clutters the UI. Additionally, when processing multiple payments, cashiers often incorrectly input cash payments first, which can cause calculation issues or confusion; they need clear instructions within the UI to input non-cash methods first. Finally, customers need to see the serial numbers of products they purchased directly on the POS receipt for warranty and tracking purposes.

## Goals / Non-Goals

**Goals:**
- Improve POS UI simplicity by hiding the unused tier field on the add customer modal.
- Reduce cashier error in multi-payment scenarios by providing an instructional note.
- Enhance receipt completeness by displaying assigned product serial numbers.

**Non-Goals:**
- Removing the tier concept entirely from the backend customer model.
- Restructuring the entire POS UI or receipt layout.
- Implementing hard validation to prevent cashiers from entering cash first (only providing an educational note).

## Decisions

- **Tier Field:** We will use the `d-none` CSS class on the tier form-group in `customer_create.blade.php`. This hides it visually but keeps it in the DOM, avoiding any JavaScript errors from missing elements.
- **Payment Note:** We will add a `<small class="text-info">` element below the "Metode Pembayaran" label in both `checkout.blade.php` and `staged_checkout.blade.php`. The text will read: "Catatan: Untuk multi payment, silakan masukkan pembayaran non-tunai (transfer/debit/kredit) terlebih dahulu, dan pembayaran tunai (cash) di akhir."
- **Serial Numbers:** `PosReceiptService` builds the line array for both checkout receipts and transaction drafts. We will add an `assigned_serials` key by falling back to `$line->line_meta['assigned_serials'] ?? []`. In `receipt.blade.php`, we will render these serials right below the product name if the array is not empty.

## Risks / Trade-offs

- **Risk:** Hiding the tier field might confuse users if they legitimately needed to set it from the POS. **Mitigation:** The tier field is marked optional anyway, and typically tier management is done in the back office.
- **Risk:** Adding static text to the modal might clutter it. **Mitigation:** Using a `<small>` tag with an info class keeps it unobtrusive while still being readable.
- **Risk:** Serial numbers might push the receipt length slightly. **Mitigation:** They will be concatenated via `implode(', ')` to save vertical space.
