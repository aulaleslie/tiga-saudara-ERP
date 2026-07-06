## ADDED Requirements

### Requirement: Sales HPP snapshot import upload
The system SHALL provide an authorized Product List import mode that accepts historical HPP ledger CSV files for updating sale detail cost snapshots after sales import has already created the target sales.

#### Scenario: Accepted HPP ledger file creates import batch
- **WHEN** an authorized user uploads a CSV with `Barang`, `Tipe Transaksi`, `No. Transaksi`, `Mutasi`, and `Harga Rata-Rata` columns through the sales HPP snapshot import mode
- **THEN** the system SHALL create a product import batch marked as a sales HPP snapshot import
- **AND** the system SHALL stage the CSV rows for asynchronous or established queued import processing
- **AND** the system SHALL expose the batch in the product import list and detail pages.

#### Scenario: Missing HPP ledger headers fails validation
- **WHEN** an authorized user uploads a CSV through the sales HPP snapshot import mode
- **AND** the file is missing any required HPP ledger column
- **THEN** the system SHALL reject the import or mark the batch failed with a message identifying the missing columns
- **AND** the system SHALL NOT update any sale detail cost snapshots from that file.

### Requirement: HPP import filters source transaction rows
The sales HPP snapshot importer SHALL update snapshots only from rows whose `Tipe Transaksi` equals `Sales Invoice`.

#### Scenario: Sales invoice row is eligible
- **WHEN** a staged HPP import row has `Tipe Transaksi` equal to `Sales Invoice`
- **THEN** the importer SHALL attempt to match that row to an existing imported sale detail.

#### Scenario: Non-sales row is skipped
- **WHEN** a staged HPP import row has `Tipe Transaksi` other than `Sales Invoice`
- **THEN** the importer SHALL mark the row skipped or ignored
- **AND** the importer SHALL NOT update any sale detail cost snapshot from that row.

### Requirement: HPP import resolves owner from product marker
The sales HPP snapshot importer SHALL resolve the target sale owner from the raw CSV `Barang` value using the same effective owner rules as sales import.

#### Scenario: Asterisk product routes to Tiga Nusa sale
- **WHEN** an eligible HPP row has `Barang` beginning with `*`
- **AND** the cleaned product name is not a Daizu product
- **THEN** the importer SHALL target a sale whose `setting_id` belongs to `CV TIGA NUSA COMPUTER`.

#### Scenario: TP suffix product routes to Top IT sale
- **WHEN** an eligible HPP row has `Barang` ending with ` TP`
- **AND** the cleaned product name is not a Daizu product
- **THEN** the importer SHALL target a sale whose `setting_id` belongs to `CV TOP IT INTERNUSA`.

#### Scenario: Unmarked product routes to Perdana sale
- **WHEN** an eligible HPP row has `Barang` without `*` prefix or ` TP` suffix
- **AND** the cleaned product name is not a Daizu product
- **THEN** the importer SHALL target a sale whose `setting_id` belongs to `PERDANA`.

#### Scenario: Daizu product overrides marker owner
- **WHEN** an eligible HPP row's product name matches the Daizu product rule
- **THEN** the importer SHALL target the Daizu sale owner
- **AND** the importer SHALL ignore `*`, ` TP`, and tag-style owner hints for target owner selection.

### Requirement: HPP import matches existing imported sale details strictly
The sales HPP snapshot importer SHALL match each eligible source row to exactly one existing sale detail by imported sales reference number, resolved owner setting, normalized clean product name, and source quantity.

#### Scenario: Exact split-owner detail match
- **WHEN** an eligible HPP row has `No. Transaksi`, resolved owner setting, cleaned product name, and `abs(Mutasi)` that match exactly one existing sale detail under a sale with the same `imported_sales_reference_number`
- **THEN** the importer SHALL treat that sale detail as the target row for snapshot overwrite.

#### Scenario: Quantity mismatch is an error
- **WHEN** an eligible HPP row matches a sale reference, resolved owner setting, and cleaned product name
- **AND** the matched sale detail quantity does not equal `abs(Mutasi)` within decimal tolerance
- **THEN** the importer SHALL mark the row as an error
- **AND** the importer SHALL NOT update that sale detail cost snapshot.

#### Scenario: Missing sale or detail is an error
- **WHEN** an eligible HPP row has no sale detail matching its `No. Transaksi`, resolved owner setting, cleaned product name, and `abs(Mutasi)`
- **THEN** the importer SHALL mark the row as an error
- **AND** the importer SHALL NOT create a sale, sale detail, product, stock row, or inventory transaction.

#### Scenario: Ambiguous detail match is an error
- **WHEN** an eligible HPP row matches more than one sale detail for the same imported sales reference, resolved owner setting, cleaned product name, and quantity
- **THEN** the importer SHALL mark the row as an ambiguous match error
- **AND** the importer SHALL NOT update any of the matching sale details.

### Requirement: HPP import overwrites matched sale detail snapshots
The sales HPP snapshot importer SHALL treat the uploaded HPP ledger as the authoritative source for matched sale detail cost snapshots.

#### Scenario: Matched row overwrites existing snapshot
- **WHEN** an eligible HPP row matches exactly one sale detail
- **AND** `Harga Rata-Rata` is a valid numeric cost
- **THEN** the importer SHALL overwrite the sale detail `cost_unit_snapshot` with `Harga Rata-Rata`
- **AND** the importer SHALL overwrite `cost_total_snapshot` with `Harga Rata-Rata * abs(Mutasi)`
- **AND** the importer SHALL set `cost_snapshot_source` to an HPP import source label
- **AND** the importer SHALL set `cost_snapshot_at` to the import processing time.

#### Scenario: Re-imported HPP row replaces previous imported value
- **WHEN** a later sales HPP snapshot import row matches a sale detail that already has cost snapshot values
- **THEN** the importer SHALL overwrite the existing cost snapshot values with the latest imported HPP row values
- **AND** the importer SHALL report the row as successfully updated.

### Requirement: HPP import reports row-level outcomes
The sales HPP snapshot importer SHALL expose row-level result details for successful updates, skipped rows, and errors.

#### Scenario: Successful row result is visible
- **WHEN** an HPP import row updates a sale detail
- **THEN** the batch detail page SHALL show the row as updated or imported
- **AND** it SHALL expose the source reference, cleaned product name, resolved owner, source quantity, imported HPP, matched sale ID, matched sale detail ID, and resulting snapshot values where supported by the import row metadata.

#### Scenario: Failed row result is visible
- **WHEN** an HPP import row cannot be applied
- **THEN** the batch detail page SHALL show the row status and error message
- **AND** the message SHALL distinguish missing sales, missing details, quantity mismatch, ambiguous matches, invalid numeric HPP, and missing owner setting where possible.
## ADDED Requirements

### Requirement: Authoritative HPP import can overwrite imported sale snapshots
The system SHALL allow an explicit sales HPP snapshot import to overwrite sale detail cost snapshots for matched imported sales, while preserving existing live-sale snapshot and historical backfill behavior.

#### Scenario: HPP import source supersedes prior snapshot
- **WHEN** a sales HPP snapshot import matches an existing imported sale detail
- **THEN** the import SHALL overwrite `cost_unit_snapshot`, `cost_total_snapshot`, `cost_snapshot_source`, and `cost_snapshot_at` for that sale detail
- **AND** the resulting HPP used by reports SHALL come from the imported HPP snapshot values.

#### Scenario: Backfill behavior remains unchanged
- **WHEN** the historical backfill command runs without force mode
- **THEN** it SHALL continue to preserve existing cost snapshots according to the existing backfill rules
- **AND** it SHALL NOT change the authoritative overwrite behavior of the explicit sales HPP snapshot import.
