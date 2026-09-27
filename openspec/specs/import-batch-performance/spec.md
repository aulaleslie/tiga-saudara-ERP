## ADDED Requirements

### Requirement: Import processing is memory-bounded by chunks
The purchase and sales importers SHALL process staged CSV import rows in bounded chunks while preserving complete source invoice groups within a processing unit.

#### Scenario: Large purchase batch does not load all rows at once
- **WHEN** a purchase import batch contains more rows than the configured processing chunk size
- **THEN** the purchase importer MUST load and process pending rows in multiple bounded chunks
- **AND** the importer MUST NOT require all pending rows for the batch to be held in memory at the same time

#### Scenario: Large sales batch remains chunked
- **WHEN** a sales import batch contains more rows than the configured processing chunk size
- **THEN** the sales importer MUST continue loading and processing pending rows in multiple bounded chunks
- **AND** the importer MUST NOT require all pending rows for the batch to be held in memory at the same time

#### Scenario: Source invoice rows stay together
- **WHEN** the final row in a processing chunk belongs to a source invoice that continues in immediately following pending rows
- **THEN** the importer MUST extend the processing unit to include the remaining contiguous pending rows for that same source invoice
- **AND** source invoice payment reconciliation MUST run with every owner group from that source invoice included

### Requirement: Performance optimizations preserve import business results
The purchase and sales importers SHALL preserve the existing imported business records and side effects while reducing repeated database work.

#### Scenario: Optimized purchase import creates equivalent records
- **WHEN** a purchase CSV batch imports successfully after optimization
- **THEN** the created purchases, purchase details, purchase payments, product stock changes, product price values, transaction logs, tax assignments, owner settings, tags, and import row statuses MUST match the existing import behavior for the same source rows

#### Scenario: Optimized sales import creates equivalent records
- **WHEN** a sales CSV batch imports successfully after optimization
- **THEN** the created sales, sale details, sale payments, dispatches, dispatch details, product stock changes, product price values, transaction logs, tax assignments, owner settings, tags, and import row statuses MUST match the existing import behavior for the same source rows

#### Scenario: Duplicate handling remains unchanged
- **WHEN** an import row group is skipped because its source purchase or sale already exists for the effective owner
- **THEN** the importer MUST mark the matching rows skipped
- **AND** the importer MUST NOT create duplicate document, payment, stock, dispatch, transaction, or price-sync side effects

### Requirement: Importers reduce repeated lookup work
The purchase and sales importers SHALL cache or preload lookup data where doing so does not change import behavior.

#### Scenario: Purchase importer preloads reusable lookup data
- **WHEN** a purchase import chunk is processed
- **THEN** the importer MUST avoid repeated per-row queries for reusable settings, taxes, units, locations, suppliers, products, product prices, and product stocks where those values can be resolved from the chunk or static lookup tables
- **AND** newly created lookup entities MUST be available to later rows in the same processing run without requiring repeated failed lookups

#### Scenario: Sales importer preserves and uses chunk caches
- **WHEN** a sales import chunk is processed
- **THEN** the importer MUST continue preloading reusable settings, taxes, units, locations, customers, products, product prices, and product stocks where those values can be resolved from the chunk or static lookup tables
- **AND** newly created lookup entities MUST be available to later rows in the same processing run without requiring repeated failed lookups

### Requirement: High-volume status and counter updates are batched safely
The purchase and sales importers SHALL reduce row-by-row import status and batch counter writes when multiple rows share the same outcome.

#### Scenario: Processed group rows are marked without per-row counter increments
- **WHEN** an invoice or owner group imports successfully
- **THEN** the importer MUST mark all rows in that group processed with the generated document id
- **AND** the batch success count MUST increase by the number of processed rows without requiring one database increment per row

#### Scenario: Invalid group rows retain error context
- **WHEN** an invoice or owner group fails with the same validation or processing error for all rows in the group
- **THEN** the importer MUST mark all rows in that group invalid with the error message
- **AND** the batch error count MUST increase by the number of invalid rows without requiring one database increment per row

#### Scenario: Skipped group rows retain existing document reference
- **WHEN** an invoice or owner group is skipped because the imported document already exists
- **THEN** the importer MUST mark all rows in that group skipped with the existing purchase or sale id
- **AND** the importer MUST NOT increment success count for skipped rows

### Requirement: Product price synchronization avoids redundant work
The purchase and sales importers SHALL avoid redundant all-settings product price synchronization while preserving the final product price values required by existing import price-sync behavior.

#### Scenario: Setting list is reused during price sync
- **WHEN** multiple import rows require product price synchronization in one processing run
- **THEN** the importer MUST reuse the resolved list of setting ids instead of querying settings for every imported detail row

#### Scenario: Sales latest positive price still wins
- **WHEN** multiple successfully processed sales import rows for the same product have different positive final tax-included unit prices
- **THEN** the final synchronized `sale_price`, `tier_1_price`, and `tier_2_price` values across every setting MUST equal the price from the row processed last by import processing order
- **AND** avoiding redundant writes MUST NOT change that final result

#### Scenario: Purchase weighted average remains correct
- **WHEN** multiple purchase import rows for the same product are processed
- **THEN** the final synchronized `last_purchase_price` and `average_purchase_price` values across every setting MUST match the existing weighted-average import semantics
- **AND** avoiding redundant writes MUST NOT use a stale prior quantity or stale prior average price

### Requirement: Import processing exposes progress and timing
The purchase and sales importers SHALL record enough progress and timing information to identify slow import phases.

#### Scenario: Batch logs include processing progress
- **WHEN** an import processing job starts, processes chunks, and completes
- **THEN** logs MUST include the batch id, chunk number where applicable, rows processed, source invoice or group count, success count, error count, skipped count where available, elapsed time, and processing rate

#### Scenario: Phase timing identifies slow sections
- **WHEN** an import chunk is processed
- **THEN** logs SHOULD identify elapsed time for major phases such as preloading, grouping, document creation, stock or dispatch side effects, product price synchronization, and import row status persistence where practical

#### Scenario: Failed processing records enough context
- **WHEN** an import source invoice or owner group fails
- **THEN** logs MUST include the batch id, source invoice number when available, affected row numbers or row count, and error message
- **AND** import row error messages MUST remain available in the import detail view

### Requirement: Large import jobs use suitable queue timeouts
The purchase and sales import processing jobs SHALL use timeout settings compatible with large historical CSV batches and the documented queue worker timeout.

#### Scenario: Purchase processing timeout supports large batches
- **WHEN** a purchase import processing job is dispatched
- **THEN** its configured job timeout MUST be high enough for large historical batches and MUST NOT be lower than the sales processing timeout without a documented reason

#### Scenario: Queue worker timeout remains compatible
- **WHEN** the application documents or configures the queue worker command for imports
- **THEN** the worker timeout MUST be greater than or equal to the longest import job timeout
- **AND** import jobs MUST remain retryable through the existing queue failure handling
## Requirements
### Requirement: Import processing is memory-bounded by chunks
The purchase and sales importers SHALL process staged CSV import rows in bounded chunks while preserving complete source invoice groups within a processing unit.

#### Scenario: Large purchase batch does not load all rows at once
- **WHEN** a purchase import batch contains more rows than the configured processing chunk size
- **THEN** the purchase importer MUST load and process pending rows in multiple bounded chunks
- **AND** the importer MUST NOT require all pending rows for the batch to be held in memory at the same time

#### Scenario: Large sales batch remains chunked
- **WHEN** a sales import batch contains more rows than the configured processing chunk size
- **THEN** the sales importer MUST continue loading and processing pending rows in multiple bounded chunks
- **AND** the importer MUST NOT require all pending rows for the batch to be held in memory at the same time

#### Scenario: Source invoice rows stay together
- **WHEN** a sales processing chunk selects pending rows for one or more source invoice numbers
- **THEN** the sales importer MUST include all pending rows in the batch for those selected source invoice numbers before source-invoice payment reconciliation
- **AND** the importer MUST use indexed staged invoice metadata for new imports rather than a repeated JSON-field scan as the normal loading path
- **AND** source invoice payment reconciliation MUST run with every owner group from each selected source invoice included

#### Scenario: Large zipped sales upload avoids whole-file memory copy
- **WHEN** a zipped sales upload contains an extracted CSV or TXT file
- **THEN** the sales upload preparation MUST move or stream-copy the extracted file into import storage
- **AND** the controller MUST NOT read the entire extracted CSV or TXT into PHP memory before dispatching staging

### Requirement: Importers reduce repeated lookup work
The purchase and sales importers SHALL cache or preload lookup data where doing so does not change import behavior.

#### Scenario: Purchase importer preloads reusable lookup data
- **WHEN** a purchase import chunk is processed
- **THEN** the importer MUST avoid repeated per-row queries for reusable settings, taxes, units, locations, suppliers, products, product prices, and product stocks where those values can be resolved from the chunk or static lookup tables
- **AND** newly created lookup entities MUST be available to later rows in the same processing run without requiring repeated failed lookups

#### Scenario: Sales importer preserves and uses chunk caches
- **WHEN** a sales import chunk is processed
- **THEN** the importer MUST continue preloading reusable settings, taxes, units, locations, customers, products, product prices, and product stocks where those values can be resolved from the chunk or static lookup tables
- **AND** newly created lookup entities MUST be available to later rows in the same processing run without requiring repeated failed lookups

#### Scenario: Sales staging avoids avoidable per-row service resolution
- **WHEN** a sales staging job maps CSV records into staged import rows
- **THEN** the staging job MUST reuse a resolved mapper or service instance for the job instead of resolving the same service from the container for every row
- **AND** this optimization MUST NOT change CSV header normalization or mapped row values

### Requirement: Import processing exposes progress and timing
The purchase and sales importers SHALL record enough progress and timing information to identify slow import phases.

#### Scenario: Batch logs include processing progress
- **WHEN** an import processing job starts, processes chunks, and completes
- **THEN** logs MUST include the batch id, chunk number where applicable, rows processed, source invoice or group count, success count, error count, skipped count where available, elapsed time, and processing rate

#### Scenario: Sales invoice expansion logs performance path
- **WHEN** sales import expands an initial pending-row window to complete selected source invoices
- **THEN** logs MUST include the initial row count, actual row count, expanded row count, selected source invoice count, and whether indexed staged invoice loading or compatibility JSON loading was used

#### Scenario: Phase timing identifies slow sections
- **WHEN** an import chunk is processed
- **THEN** logs SHOULD identify elapsed time for major phases such as preloading, grouping, document creation, stock or dispatch side effects, product price synchronization, and import row status persistence where practical

#### Scenario: Failed processing records enough context
- **WHEN** an import source invoice or owner group fails
- **THEN** logs MUST include the batch id, source invoice number when available, affected row numbers or row count, and error message
- **AND** import row error messages MUST remain available in the import detail view

