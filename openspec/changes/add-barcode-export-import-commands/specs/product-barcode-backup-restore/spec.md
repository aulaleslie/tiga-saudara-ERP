## ADDED Requirements

### Requirement: Barcodes can be exported to a name-keyed file
The system SHALL provide a console command that writes every product currently holding a barcode to a CSV file keyed by product name, so that barcodes survive a destructive database cleanup.

The exported product name SHALL be written verbatim, exactly as stored in `products.product_name`, with no normalization, marker stripping, alias mapping, case folding, or whitespace collapsing applied.

#### Scenario: Products with barcodes are exported
- **WHEN** the export command runs and products exist both with and without barcodes
- **THEN** the CSV SHALL contain one row per product whose barcode is neither null nor an empty string
- **AND** each row SHALL contain that product's name and barcode
- **AND** products without a barcode SHALL NOT appear in the file

#### Scenario: Product names are written verbatim
- **WHEN** a barcoded product's name carries a leading asterisk, a trailing marker suffix, mixed case, or internal punctuation
- **THEN** the exported name SHALL be byte-identical to the stored `product_name`

#### Scenario: Near-duplicate names are disambiguated by the barcode filter
- **WHEN** two products have names differing only by case or spacing and only one of them holds a barcode
- **THEN** only the barcoded product SHALL be written to the file

#### Scenario: Export reports its row count for verification
- **WHEN** the export command completes
- **THEN** it SHALL report the number of rows written
- **AND** the operator SHALL be able to compare that count against the number of barcoded products in the source table before running the destructive cleanup

#### Scenario: No barcodes exist to export
- **WHEN** the export command runs and no product holds a barcode
- **THEN** it SHALL report a count of zero rather than failing

### Requirement: Barcodes can be restored by exact product name
The system SHALL provide a console command that reads a previously exported file and restores each barcode to the product whose `product_name` matches the file's name exactly.

A row SHALL be applied only when the name matches exactly one product.

#### Scenario: Exact name match restores the barcode
- **WHEN** a file row names a product that exists exactly once and holds no barcode
- **THEN** the system SHALL set that product's barcode to the value from the file
- **AND** the row SHALL be counted as applied

#### Scenario: No matching product is reported as not found
- **WHEN** a file row names a product that does not exist
- **THEN** the system SHALL NOT write any barcode for that row
- **AND** the row SHALL be reported under the `not_found` category

#### Scenario: Multiple matching products are skipped as ambiguous
- **WHEN** a file row's name matches two or more products, including matches arising from case-insensitive collation
- **THEN** the system SHALL NOT write a barcode to any of them
- **AND** the row SHALL be reported under the `ambiguous` category

#### Scenario: Product identity is not scoped by setting
- **WHEN** a file row names a product whose `setting_id` differs from the value it held before the cleanup
- **THEN** the match SHALL still succeed, because product name matching SHALL NOT be filtered by `setting_id`

### Requirement: Restore fills blank barcodes only and never overwrites
The restore SHALL assign a barcode only to a product whose current barcode is null or an empty string. A product already holding a non-blank barcode SHALL be left untouched and reported.

#### Scenario: Existing barcode is preserved
- **WHEN** a file row matches a product that already holds a different barcode
- **THEN** the product's existing barcode SHALL remain unchanged
- **AND** the row SHALL be reported under the `has_barcode` category

#### Scenario: Restore is idempotent across repeated runs
- **WHEN** the restore command is run a second time against the same file
- **THEN** no barcode SHALL be modified
- **AND** every previously applied row SHALL be reported under the `has_barcode` category

#### Scenario: Products barcoded by a data import are reported rather than overwritten
- **WHEN** a product was created by a data import that already supplied a barcode, and the file also carries a barcode for that name
- **THEN** the import-supplied barcode SHALL be preserved
- **AND** the row SHALL be reported under the `has_barcode` category

### Requirement: Restore keeps the barcode registry consistent with the column
Each restored barcode SHALL be written to both `products.barcode` and a corresponding `barcode_identities` registry row within a single database transaction, so that subsequent barcode assignments made through the application cannot be issued a value that a restored product already holds.

The registry row's canonical key SHALL be derived using the same canonicalization used elsewhere in the system.

#### Scenario: Registry row accompanies every restored barcode
- **WHEN** the restore applies a barcode to a product
- **THEN** a `barcode_identities` row SHALL exist for that barcode referencing that product
- **AND** its canonical key SHALL be the canonicalized form of the barcode value

#### Scenario: Column and registry are written atomically
- **WHEN** writing the registry row for a given product fails
- **THEN** that product's barcode column SHALL NOT retain the new value
- **AND** the run SHALL continue processing the remaining rows

#### Scenario: A later interactive assignment cannot collide with a restored barcode
- **WHEN** a user assigns a barcode through the barcode workspace after a restore, using a value already held by a restored product
- **THEN** the assignment SHALL be rejected as a duplicate

#### Scenario: Restore does not record assignment audit history
- **WHEN** the restore applies barcodes to products
- **THEN** no barcode assignment audit rows SHALL be created, because restored values are reinstated prior state rather than new assignments

### Requirement: Restore reports every outcome and survives constraint violations
The restore SHALL process every row in the file, skipping rather than aborting on any row it cannot apply, and SHALL report a summary count for each outcome category: applied, `not_found`, `ambiguous`, `has_barcode`, and `barcode_taken`.

#### Scenario: A barcode already owned by another product is skipped
- **WHEN** a file row's barcode value is already held by a different product, violating a uniqueness constraint
- **THEN** the system SHALL report that row under the `barcode_taken` category
- **AND** the command SHALL continue processing subsequent rows rather than terminating

#### Scenario: Summary counts are reported per category
- **WHEN** the restore command completes
- **THEN** it SHALL report the number of barcodes applied
- **AND** it SHALL report a count for each skip category
- **AND** skipped rows SHALL be identifiable by product name so the operator can act on them

#### Scenario: A mixed file is fully processed
- **WHEN** a file contains a mixture of applicable rows, unmatched names, ambiguous names, and already-barcoded products
- **THEN** every applicable row SHALL be applied
- **AND** every non-applicable row SHALL appear in its corresponding skip category

### Requirement: Restore supports a no-write preview
The restore command SHALL accept a dry-run option that performs all matching and reporting without writing any barcode or registry row.

#### Scenario: Dry run reports outcomes without writing
- **WHEN** the restore command runs with the dry-run option
- **THEN** it SHALL report the same outcome categories it would produce in a real run
- **AND** no product barcode SHALL be modified
- **AND** no `barcode_identities` row SHALL be created

#### Scenario: Dry run surfaces unmatched rows before committing
- **WHEN** the operator runs a dry run and the `not_found` count is unexpectedly high
- **THEN** the operator SHALL be able to halt before any write occurs
