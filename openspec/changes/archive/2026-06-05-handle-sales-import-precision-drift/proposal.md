## Why

Historical sales exports can contain high-precision unit prices and tax amounts that no longer recompute to the rounded source `Total` exactly. Invoice `TN.20211796` demonstrates a likely-valid paid sale where the source `Total` and `Pembayaran` are `126,964,600.00`, but the importer recomputes `126,964,597.07` from exported line values and rejects the invoice because the drift exceeds the current `1.00` source-total tolerance.

## What Changes

- Add a narrow precision-drift path for sales imports when the source `Total`, payment fields, current paid status, and recomputed document total are internally consistent except for a very small absolute/relative rounding drift.
- Preserve strict rejection for missing/corrupted row data, conflicting repeated payment fields, large total mismatches, and payment/outstanding mismatches.
- Record enough detail in logs or row error context to distinguish accepted precision drift from ordinary exact reconciliation.
- Add focused regression coverage for the `TN.20211796` shape and for nearby invalid mismatches that must still fail.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `import-document-total-reconciliation`: Allow a narrowly bounded historical sales precision drift to reconcile against source `Total` when settlement fields also reconcile, while preserving strict rejection of real mismatches.

## Impact

- Affected code: `App\Support\ImportPaymentSummaryResolver`, `Modules/Sale/Services/SalesImportService`, and focused sales import/payment reconciliation tests.
- Affected behavior: sales import validation for historical invoices whose exported line/tax precision differs slightly from the source document total.
- No database schema changes are expected.
- No public API or dependency changes are expected.
