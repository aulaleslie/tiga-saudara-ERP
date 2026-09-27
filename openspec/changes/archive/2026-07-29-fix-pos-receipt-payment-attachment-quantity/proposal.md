## Why

POS receipt line totals can expose an internal minor-unit value, causing a product total of Rp45.000 to print as Rp4.500.000 even though checkout, payment, and grand-total values are correct. In the staged multi-payment UI, advancing from an attached non-cash stage to a cash stage can delete the already-staged attachment, making finalization fail. POS quantity labels also add display formatting when the intended value is the raw quantity.

## What Changes

- Render POS receipt product-row totals in the same Rupiah unit used by the cart, checkout totals, payment values, and grand total for both completed and draft receipts.
- Preserve an attachment after its non-cash payment stage has been committed, including when a later cash stage is selected; keep cash stages attachment-free.
- Ensure reset or cancellation cleanup removes only uncommitted payment-image uploads, while a complete payment-chain reset removes the chain's unconsumed temporary uploads.
- Display POS quantities as raw normalized values, without locale/thousand-separator or fixed-decimal formatting, across receipt, transaction, return, bundle, and item-sales-report UI.
- Add regression coverage for the affected receipt, staged multi-payment, attachment isolation, and quantity-display paths.

## Capabilities

### New Capabilities

- `pos-quantity-raw-display`: Consistent raw, unformatted quantity rendering throughout POS UI.

### Modified Capabilities

- `pos-receipt`: Receipt line totals must use the same displayed currency unit as POS checkout totals.
- `pos-draft-receipt`: Draft receipt line totals must use the same displayed currency unit as POS checkout totals.
- `pos-non-cash-payment-image`: A committed non-cash stage's image must survive transitions to later stages and remain isolated from cash stages.

## Impact

- Affects `Modules/Pos/Services/PosReceiptService`, receipt and transaction/return/report Blade views, staged-payment browser JavaScript, temporary-image cleanup behavior, and POS feature tests.
- No schema, public API, or payment-method configuration change is expected.
