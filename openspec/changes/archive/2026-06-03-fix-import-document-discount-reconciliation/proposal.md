## Why

Purchase and sales CSV imports reject valid discounted invoice groups because payment reconciliation compares `Total`, `Pembayaran`, and outstanding balance against a calculated line total that ignores document-level `Diskon` and `Biaya Pengiriman`. This now blocks imports whose source exports repeat invoice-level discount and shipping values on each line.

## What Changes

- Treat CSV `Diskon` as a document-level fixed discount amount for purchase and sales imports.
- Ignore CSV `Diskon %` for import math because it is rounded/display-oriented and can drift from the exact source total.
- Apply document `Diskon` once per invoice and owner group, not once per CSV row.
- Treat CSV `Biaya Pengiriman` as a document-level shipping amount applied once per invoice and owner group.
- Validate repeated document-level `Diskon` and `Biaya Pengiriman` values within an invoice and owner group, failing the whole group when non-blank values conflict.
- Reconcile `source_total`, `Pembayaran`, and preferred outstanding balance against the adjusted imported document total: line total minus document discount plus document shipping.
- Persist imported documents with `discount_percentage = 0`, `discount_amount = Diskon`, `shipping_amount = Biaya Pengiriman`, and adjusted `total_amount`.
- Preserve existing line discount handling for `Diskon Per Baris %`.

## Capabilities

### New Capabilities

- `import-document-total-reconciliation`: Defines how purchase and sales CSV imports interpret document-level discount and shipping fields before validating totals and creating payment rows.

### Modified Capabilities

- None.

## Impact

- Affected services: `Modules/Purchase/Services/PurchaseImportService.php`, `Modules/Sale/Services/SalesImportService.php`, and shared import payment reconciliation flow.
- Affected staging/mapping: purchase and sales CSV header normalization and row payloads for `Diskon`, `Diskon %`, and `Biaya Pengiriman`.
- Affected models/tables: imported `purchases`, `sales`, `purchase_payments`, and `sale_payments` values created by future import runs.
- Affected tests: focused purchase and sales import tests for document discount, shipping, repeated-field conflict validation, and payment reconciliation.
