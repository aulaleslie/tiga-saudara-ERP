# Proposal

## Why

The global purchase payment form currently uploads one file for the whole submission and duplicates it onto every generated payment. Operators need to provide evidence for each purchase separately, using the multiple-file support already available on individual purchase payments.

## What Changes

- Add an attachment upload control to each purchase allocation row while retaining the existing purchase context and amount columns.
- Allow zero or more supported files per positive allocation, associated only with that row's new `PurchasePayment`.
- Block a submission when a zero-amount row has staged attachments, when an upload is still pending, or when any submitted row attachment is invalid.
- Replace the global form's shared single-file upload and replication behavior for future payments. Existing payment records and their attachments stay intact.
- Reuse the individual payment attachment types, validation, and presentation conventions.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-purchase-multi-payment`: Replace shared attachment replication with optional multiple attachments scoped to each positive allocation row, including validation and atomic failure behavior.
- `individual-purchase-payment-attachments`: Remove the historical guarantee that the global workflow retains its previous single-attachment behavior; state that the same supported file rules apply to global row uploads.

## Impact

The global purchase payment form, controller request contract, `GlobalPurchasePaymentService`, and focused purchase payment tests will change. The existing staging service, payment media collection, and attachment views can be reused. No database migration or historical media rewrite is planned.
