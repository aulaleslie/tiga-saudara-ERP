## Why

Sales and purchase payment records currently expose monetary edit forms whose legacy field contracts no longer match their controllers, producing validation failures such as a missing payment method and allowing settled amounts to be rewritten in place. Payment maintenance should protect financial facts while still making notes easy to read and correct, and payment removal must always leave the parent document balance consistent with the remaining active payments.

## What Changes

- **BREAKING**: Remove user-facing and server-side support for editing payment amount, date, reference, payment method, parent document, attachment, and payment status after a sales or purchase payment is created.
- Replace payment Edit actions with View actions that open a read-only payment detail and expose an authorized note-only modification control.
- Add payment notes to the sales-payment and purchase-payment tables so users can read them without opening each payment.
- Allow authorized users to immediately delete eligible payments and atomically recalculate the parent sale or purchase paid amount, due amount, and payment status from the remaining active payment records.
- Preserve accounting safeguards for payments whose linked credit or other dependent settlement records cannot be removed safely, and preserve existing automated invalidation behavior used by return workflows.
- Harden parent/payment association, active-setting ownership, authorization, validation, and immutable-field handling for payment detail, note update, and deletion requests.

## Capabilities

### New Capabilities

- `payment-record-maintenance`: Defines read-only sales and purchase payment details, note-only modification, note visibility in payment tables, immutable financial fields, eligible payment deletion, and canonical balance reconciliation.

### Modified Capabilities

- None.

## Impact

- Affects normal setting-scoped sales and purchase payment routes, controllers, DataTables, action partials, and payment detail templates in `Modules/Sale` and `Modules/Purchase`.
- Reuses the existing `Sale`, `Purchase`, `SalePayment`, and `PurchasePayment` models and active-payment balance semantics; no database migration or new dependency is expected.
- Existing payment-edit URLs and update endpoints change from general payment editing to read-only detail plus note-only updates.
- Requires focused feature and DataTable tests for authorization, ownership, immutability, note presentation/update, eligible deletion, dependency guards, and balance/status reconciliation.
