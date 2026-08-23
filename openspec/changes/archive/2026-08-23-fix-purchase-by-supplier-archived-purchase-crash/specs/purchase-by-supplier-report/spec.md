## ADDED Requirements

### Requirement: Purchase by supplier excludes archived purchases
The system SHALL exclude purchase details whose parent purchase is archived from the normal Purchase by Supplier dataset. The exclusion MUST apply consistently to on-screen rows, filtered result counts, pagination, sorting, running totals, grand totals, and Excel and CSV exports.

#### Scenario: Archived purchase matches the active filters
- **WHEN** an archived purchase belongs to the active setting and its effective purchase date, supplier, tags, and product categories match the applied filters
- **THEN** none of its purchase detail rows are included in the Purchase by Supplier report
- **AND** applying the filters and rendering the report completes without an error

#### Scenario: Active and archived purchases match together
- **WHEN** an active purchase and an archived purchase both belong to the active setting and match the applied filters
- **THEN** the report includes the active purchase detail rows
- **AND** the report excludes the archived purchase detail rows
- **AND** result counts, pagination, sorting, running totals, and grand totals are calculated only from the active purchase detail rows

#### Scenario: Export uses the same non-archived dataset
- **WHEN** a user exports applied filters that match both active and archived purchases
- **THEN** the Excel or CSV export contains the matching active purchase rows
- **AND** the export does not contain rows or monetary contributions from the archived purchase
