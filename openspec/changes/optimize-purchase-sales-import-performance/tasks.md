## 1. Baseline and Safety Checks

- [x] 1.1 Review current purchase and sales import flows in `Modules/Purchase/Services/PurchaseImportService.php`, `Modules/Sale/Services/SalesImportService.php`, and their stage/process jobs.
- [x] 1.2 Add or update focused tests that capture current purchase import outputs for a multi-row invoice, duplicate invoice, paid invoice, and invalid invoice group.
- [x] 1.3 Add or update focused tests that capture current sales import outputs for a multi-row invoice, duplicate invoice, paid invoice, dispatch side effects, and invalid invoice group.
- [x] 1.4 Add a performance characterization test or command path that can report rows, source documents, elapsed time, and major record counts for representative staged import rows without requiring the browser.

## 2. Query and Queue Foundations

- [x] 2.1 Add or verify import-row indexes that support `(batch_id, status, row_number)` chunk reads for purchase and sales import rows.
- [x] 2.2 Verify duplicate-check indexes for purchase supplier invoice plus setting and sales imported reference plus setting; add missing additive indexes if needed.
- [x] 2.3 Align `ProcessPurchaseImportBatch` timeout with large batch expectations and the documented queue worker timeout.
- [x] 2.4 Confirm README or operational documentation keeps the queue worker timeout greater than or equal to the longest import job timeout.

## 3. Purchase Chunking and Caching

- [x] 3.1 Modify `PurchaseImportService` state to hold arrays caching Setting and PaymentMethod lookups by mapped name strings across the batch.
- [x] 3.2 Refactor `processBatch` to process rows in loop chunks (e.g., 200 or 500 rows) by reading from `purchase_import_rows` where status is 'pending' ordered by `row_number`.
- [x] 3.3 Implement document-grouping pre-aggregation during the chunk mapping step: identify unique supplier invoice keys (reference + setting_id) and preload matching existing `Purchase` models with their lines.
- [x] 3.4 Update the per-row logic to insert newly created/updated `Purchase` and `PurchasePayment` models in bulk or save them immediately, reusing the cached document references to prevent repeated duplicated-check queries.
- [x] 3.5 Validate that the updated batch process correctly updates the batch's `processed_rows`, `success_count`, and `error_count` columns accurately at the end of each chunk.
- [x] 3.6 Run the `PurchaseImportReproductionTest` to verify that parity is completely retained. across multiple chunks and source invoice rows remain together.

## 4. Shared Write Reduction

- [ ] 4.4 Deduplicate repeated `Setting::pluck('id')` calls during purchase and sales product price synchronization.
- [ ] 4.5 Reduce redundant all-settings sales price writes while preserving "latest positive processed sales price wins" semantics.
- [ ] 4.6 Reduce redundant purchase price sync setup while preserving weighted-average purchase price semantics.

## 5. Instrumentation

- [x] 5.1 Add purchase processing logs for job start, chunk start/end, rows, source invoices, success/error/skipped counts, elapsed time, and processing rate.
- [x] 5.2 Add sales processing logs for any missing progress fields not already logged, including skipped counts and processing rate.
- [x] 5.3 Add phase timing logs for preloading, grouping, invoice processing, stock or dispatch side effects, price sync, and import row status persistence where practical.
- [x] 5.4 Ensure failed invoice/group logs include batch id, source invoice number, affected row numbers or row count, and error message.

## 6. Verification

- [x] 6.1 Run focused unit tests for import parsing, document adjustment, payment allocation, payment ledger, price sync, and ownership behavior.
- [x] 6.2 Run focused feature tests for purchase import behavior after chunking and caching changes.
- [x] 6.3 Run focused feature tests for sales import behavior after write-reduction and instrumentation changes.
- [x] 6.4 Run a representative local or staging import using one purchase CSV and one sales CSV sample and record elapsed time, rows per second, documents per second, success count, error count, and skipped count.
- [x] 6.5 Run `openspec validate optimize-purchase-sales-import-performance --strict`.
