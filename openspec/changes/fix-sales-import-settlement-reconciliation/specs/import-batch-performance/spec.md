## MODIFIED Requirements

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
