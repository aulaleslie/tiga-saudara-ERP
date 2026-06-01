## Why

Purchase and sales CSV imports currently set document-level `paid_amount`, `due_amount`, and `payment_status`, but they do not create matching `purchase_payments` or `sale_payments` records. This leaves imported paid or partially paid documents with empty payment histories and breaks features that treat active payment rows as the auditable source of truth.

## What Changes

- Create an active purchase payment row for each future imported purchase document whose imported paid amount is greater than zero.
- Create an active sale payment row for each future imported sale document whose imported paid amount is greater than zero.
- Use the CSV `Pembayaran` value as the preferred payment amount source, falling back to `document total - outstanding balance` only when `Pembayaran` is blank or missing.
- Prefer `Sisa Tagihan Hari Ini` for outstanding balance when present, falling back to `Sisa Tagihan`.
- Validate invoice-group payment fields for line-oriented CSV imports so repeated document-level fields remain consistent across all rows in the same invoice and owner group.
- Fail the whole invoice group when payment amount, outstanding balance, and calculated document total do not reconcile.
- Use the existing cash payment method for generated import payment records, the document date as payment date, and the generated ERP document reference as payment reference.
- Do not create payment rows for fully unpaid imported documents.
- Do not backfill historical imports as part of this change.

## Capabilities

### New Capabilities

- `import-payment-ledger-consistency`: Defines how purchase and sales CSV imports must create auditable payment rows that reconcile with imported document balances.

### Modified Capabilities

- None.

## Impact

- Affected services: `Modules/Purchase/Services/PurchaseImportService.php`, `Modules/Sale/Services/SalesImportService.php`.
- Affected import staging/mapping: purchase and sales CSV header normalization for `Sisa Tagihan Hari Ini`, `Sisa Tagihan`, and `Pembayaran`.
- Affected models/tables: `purchase_payments`, `sale_payments`, `payment_methods`, `purchases`, `sales`.
- Affected tests: focused purchase import and sales import tests for paid, partial, unpaid, mismatch, missing cash method, and repeated invoice-field validation cases.
