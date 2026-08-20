## ADDED Requirements

### Requirement: Sales by product report supports selectable company scope
The system SHALL allow a user with `saleReports.access` to choose which companies/settings are included in the "Penjualan per produk" report. The selected scope SHALL apply to sold quantity, sold value, return quantity, return value, and all report totals. The scope selector SHALL use the shared business source selector component so its styling and multi-select behaviour match `/profit-loss-report` and the operational reports.

#### Scenario: Default scope uses current setting
- **WHEN** a permitted user opens the sales by product report without selecting any company scope
- **THEN** the report SHALL aggregate using only the current `session('setting_id')`
- **AND** the report output SHALL match the output produced before selectable scope was introduced

#### Scenario: User selects multiple settings
- **WHEN** a permitted user selects two or more settings in the company scope filter and applies the report
- **THEN** the report SHALL aggregate sold quantity, sold value, return quantity, and return value using only records whose `sales.setting_id` or `sale_returns.setting_id` is one of the selected settings
- **AND** records from unselected settings SHALL NOT affect any row or total

#### Scenario: User selects all settings
- **WHEN** a permitted user selects every available setting in the company scope filter and applies the report
- **THEN** the report SHALL aggregate across all selected settings
- **AND** the report scope label SHALL indicate `Semua Perusahaan`

#### Scenario: Scope label reflects a single selected company
- **WHEN** exactly one setting is in effect for the report
- **THEN** the report scope label SHALL display that setting's company name

#### Scenario: Unknown or unavailable settings are discarded
- **WHEN** the submitted scope contains an identifier that does not match an available setting
- **THEN** that identifier SHALL be discarded before the report is generated
- **AND** the report SHALL aggregate using only the remaining valid settings

### Requirement: Returns follow the selected company scope
The report SHALL scope received sales returns by `sale_returns.setting_id` against the same selected setting set used for sales, preserving the existing received-status and return-date rules.

#### Scenario: Return from an unselected setting is excluded
- **WHEN** a received sales return belongs to a setting that is not selected
- **THEN** its return detail quantities and return values SHALL NOT be included in the report

#### Scenario: Returns across selected settings are combined
- **WHEN** received sales returns exist in two selected settings for the same product
- **THEN** their return quantities and return values SHALL be combined into that product's aggregate

### Requirement: Company scope is normalized before filter hashing
The report SHALL normalize the selected setting identifiers to sorted, sequentially indexed integers before the filter set is hashed for export snapshot validation. Selection order, value type, and array key gaps SHALL NOT change the resulting hash.

#### Scenario: Selection order does not invalidate an export
- **WHEN** a user applies filters selecting settings in one order, then re-selects the same settings in the reverse order
- **THEN** the computed filter hash SHALL be identical
- **AND** the export SHALL remain permitted

#### Scenario: String and integer identifiers hash identically
- **WHEN** the scope is supplied as string identifiers from the selector component
- **AND** an equivalent scope is supplied as integer identifiers
- **THEN** both SHALL produce the same filter hash

#### Scenario: Discarded identifiers do not leave key gaps
- **WHEN** a falsy or invalid identifier is removed from the submitted scope
- **THEN** the remaining identifiers SHALL be reindexed sequentially before hashing
- **AND** the resulting hash SHALL equal the hash of the same identifiers submitted without the invalid entry

#### Scenario: Changing the selected companies invalidates the export
- **WHEN** a user applies filters, then changes the company scope to a different set of settings
- **THEN** the system SHALL refuse export until the filters are applied again

### Requirement: Company scope is validated
The report SHALL validate the submitted company scope as an optional array of integer setting identifiers before it is used to build report queries.

#### Scenario: Non-integer scope entries are rejected
- **WHEN** the submitted company scope contains a non-integer entry
- **THEN** validation SHALL fail and the report SHALL NOT be generated from that input

#### Scenario: Empty scope is accepted
- **WHEN** the submitted company scope is empty or absent
- **THEN** validation SHALL pass
- **AND** the report SHALL fall back to the current `session('setting_id')`

### Requirement: Exports carry the selected company scope
Excel and CSV exports SHALL use the same selected setting set as the on-screen report, so exported rows and totals match what the user sees.

#### Scenario: Export matches multi-company on-screen rows
- **WHEN** a user applies a multi-company scope and exports the report
- **THEN** the exported product rows and totals SHALL match the on-screen report for that scope

#### Scenario: Persisted snapshot retains the selected scope
- **WHEN** a report snapshot is created for a multi-company scope
- **THEN** the snapshot SHALL persist the full selected setting set
