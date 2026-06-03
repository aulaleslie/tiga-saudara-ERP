## Why

Large historical purchase and sales CSV imports are slow enough to block practical data loading, especially sales imports with tens of thousands of rows and more than ten thousand source documents per file. The current importers preserve important ERP side effects, but they perform many repeated lookups and row-level writes that scale poorly as source files grow.

## What Changes

- Add an import performance contract for purchase and sales CSV batch processing.
- Optimize purchase import processing to use bounded chunks, preload/cached lookups, and timeout settings suitable for large batches.
- Reduce repeated database work in both purchase and sales imports without changing imported document, payment, stock, tax, owner, tag, price, dispatch, or transaction-log semantics.
- Add timing and progress observability so slow phases can be identified during staging/UAT and production imports.
- Keep imports asynchronous and resumable through the existing import batch/import row model.
- No breaking changes are intended.

## Capabilities

### New Capabilities
- `import-batch-performance`: Performance, memory-safety, progress, and observability requirements for purchase and sales CSV import batches.

### Modified Capabilities

None.

## Impact

- Affected modules:
  - `Modules/Purchase/Jobs/StagePurchaseImportRows.php`
  - `Modules/Purchase/Jobs/ProcessPurchaseImportBatch.php`
  - `Modules/Purchase/Services/PurchaseImportService.php`
  - `Modules/Sale/Jobs/StageSalesImportRows.php`
  - `Modules/Sale/Jobs/ProcessSalesImportBatch.php`
  - `Modules/Sale/Services/SalesImportService.php`
- Affected tables and indexes:
  - `purchase_import_batches`, `purchase_import_rows`
  - `sales_import_batches`, `sales_import_rows`
  - `purchases`, `purchase_details`, `purchase_payments`
  - `sales`, `sale_details`, `sale_payments`, `dispatches`, `dispatch_details`
  - `products`, `product_prices`, `product_stocks`, `transactions`
  - supporting lookup tables such as settings, locations, taxes, units, suppliers, and customers
- Verification should focus on import correctness parity plus performance/scale behavior for representative purchase and sales CSV batches.
