## ADDED Requirements

### Requirement: Chunked sales uploads preserve source file bytes
The sales import page SHALL reconstruct chunked upload files without inserting, removing, or transforming bytes between browser-provided chunks before staging begins.

#### Scenario: CSV row crosses chunk boundary
- **WHEN** a sales CSV upload is sent in multiple chunks
- **AND** a CSV row crosses a chunk boundary
- **THEN** the reconstructed stored file MUST match the original selected file bytes
- **AND** the CSV reader MUST receive the row as one complete record

#### Scenario: Historical invoice row retains unit price after upload
- **WHEN** `Sales-2020-Q4.csv` is uploaded through the sales import page
- **AND** invoice `JL2915` row `STIKER NAMA UNDANGAN POLOS (103)` crosses the 1 MB chunk boundary before `Harga per Unit`
- **THEN** staging MUST preserve the source `Harga per Unit` value `2400.0`
- **AND** the row MUST NOT be split into separate CSV records by chunk reconstruction

#### Scenario: Chunked ZIP upload remains byte-preserved
- **WHEN** a ZIP file is uploaded through the chunked sales import path
- **THEN** the reconstructed ZIP file MUST match the browser-selected ZIP bytes before extraction
- **AND** extraction MUST operate on the reconstructed ZIP rather than on partially transformed chunk text

### Requirement: Corrupted historical staging is not auto-repaired
The system SHALL NOT mutate existing staged sales import rows solely because chunk reconstruction behavior changes.

#### Scenario: Existing invalid batch remains auditable
- **WHEN** the fix is deployed
- **THEN** existing sales import batches and `sales_import_rows.raw_json` values MUST remain unchanged
- **AND** users MUST be able to re-upload the original source CSV to create a clean batch
