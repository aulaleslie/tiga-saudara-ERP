## Why

Sales import can reject valid paid invoices when CSV exports contain sub-cent precision artifacts in `Total`, `Pajak`, `Diskon`, or near-zero outstanding fields. The current flow reconciles source-invoice settlement using one rounded total, then recomputes owner document totals with a different rounding path, causing false `Payment total mismatch` failures such as a `Lunas` invoice whose money fields round to `550000.00` but whose detail recomputation lands at `550000.02`.

## What Changes

- Normalize sales import monetary reconciliation around a single canonical adjusted owner document total after source-total adjustment.
- Treat sub-cent source/export artifacts as money-rounded values while preserving existing rejection behavior for real source total mismatches.
- Ensure generated sale headers, `paid_amount`, `due_amount`, active payment rows, and owner-split allocations all use the same canonical totals.
- Add focused regression coverage for `Lunas` sales rows with scientific-notation near-zero outstanding, high-precision tax/discount fields, and one-cent source-total deltas.
- No breaking changes.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `import-payment-ledger-consistency`: Clarify that `Lunas` sales imports with source money fields that round to paid must create paid documents and payment rows that settle canonical generated totals.
- `import-document-total-reconciliation`: Clarify sales import must use one canonical adjusted total for payment reconciliation, sale header persistence, and final group validation when source `Total` is authoritative.
- `import-split-owner-payment-allocation`: Clarify owner-split sales imports must allocate source-total rounding adjustments before settlement allocation so each owner document balances independently and the invoice totals balance in aggregate.

## Impact

- Affected code: `Modules/Sale/Services/SalesImportService.php`, `app/Support/ImportPaymentSummaryResolver.php` if helper behavior needs centralizing, and existing import allocation support classes only if canonical total calculation is extracted.
- Affected tests: focused sales import payment ledger/reconciliation tests, plus any owner-split sales import tests that assert generated sale totals and payments.
- Data/API impact: no schema changes, no public API changes, and no changes to historical imported records.
