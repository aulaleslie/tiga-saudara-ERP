## ADDED Requirements

### Requirement: Buku Besar loads bucket details on demand

The system SHALL render Buku Besar bucket summaries initially without hydrating every movement row for every selected bucket. Each bucket summary SHALL show the bucket label, beginning balance, period debit, period credit, and ending balance for the active filters. Movement rows SHALL be loaded only for a bucket the user expands.

#### Scenario: Initial report shows bucket summaries

- **WHEN** a user applies Buku Besar filters
- **THEN** the report shows matching bucket summaries with balances and period totals
- **AND** the initial render does not require all selected buckets' movement rows to be present in the Livewire view data

#### Scenario: Expanding a bucket loads only that bucket

- **WHEN** a user expands a Buku Besar bucket
- **THEN** the system loads and displays movement rows for that bucket using the active date range, selected business source scope, and bucket filters
- **AND** other collapsed buckets remain summary-only

#### Scenario: Collapsing a bucket hides details without changing totals

- **WHEN** a user collapses an expanded Buku Besar bucket
- **THEN** the bucket summary remains visible
- **AND** the bucket's beginning balance, period debit, period credit, and ending balance remain unchanged

#### Scenario: Filter changes clear expanded details

- **WHEN** the user changes the date range, selected business source scope, or selected buckets and reapplies filters
- **THEN** previously expanded Buku Besar bucket details are cleared
- **AND** subsequent expansions use the newly applied filters

## MODIFIED Requirements

### Requirement: Buku Besar exports XLSX matching on-screen data

The system SHALL allow authorized users to export the filtered Buku Besar report to XLSX using the same calculation output and business source scope represented by the on-screen summaries. The export SHALL include full movement rows for all selected buckets regardless of which buckets are expanded or collapsed on screen.

#### Scenario: Export uses current filters

- **WHEN** the user exports Buku Besar after selecting a date range, bucket filter, and business source scope
- **THEN** the XLSX file uses the same date range, selected buckets, selected business source scope, rows, and balances represented by the filtered report
- **AND** the export includes full movement rows for all selected buckets even if those buckets are collapsed on screen

#### Scenario: Export includes report note

- **WHEN** the XLSX file is generated
- **THEN** it includes the operational-transaction source note

#### Scenario: Export labels selected scope

- **WHEN** the XLSX file is generated
- **THEN** the export header identifies the effective business source scope as the selected company name, `Semua Perusahaan`, or `Beberapa Perusahaan`
