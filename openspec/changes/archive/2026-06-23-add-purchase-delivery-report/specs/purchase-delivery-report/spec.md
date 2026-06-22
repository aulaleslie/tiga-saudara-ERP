## ADDED Requirements

### Requirement: Purchase delivery report entry point
The system SHALL provide a `Pengiriman pembelian` report reachable from the Reports module and gated by `purchaseReports.access`.

#### Scenario: Authorized user opens the report
- **WHEN** a user with `purchaseReports.access` requests the purchase delivery report route
- **THEN** the system displays the `Pengiriman pembelian` report page

#### Scenario: Unauthorized user is denied
- **WHEN** a user without `purchaseReports.access` requests the purchase delivery report route
- **THEN** the system returns 403

#### Scenario: Reports landing card is actionable
- **WHEN** a user with `purchaseReports.access` views the `Pembelian` tab on the Reports landing page
- **THEN** the `Pengiriman pembelian` card links to the purchase delivery report route
- **AND** the card does not show placeholder or unavailable-state treatment

### Requirement: Purchase delivery rows are sourced from approved receiving notes
The report SHALL calculate delivered purchase quantities from existing `received_notes` and `received_note_details` records, using only approved receiving notes and filtering by `received_notes.date`.

#### Scenario: Approved receiving note included
- **WHEN** an approved receiving note has a date inside the selected report period
- **THEN** its receiving detail quantities are included in the report

#### Scenario: Pending and rejected receiving notes excluded
- **WHEN** a pending or rejected receiving note has a date inside the selected report period
- **THEN** its receiving detail quantities are not included in the report

#### Scenario: Purchase date does not control inclusion
- **WHEN** a purchase date is outside the selected period but its approved receiving note date is inside the selected period
- **THEN** the receiving detail quantities are included in the report

#### Scenario: Report is scoped to active setting
- **WHEN** a user applies purchase delivery report filters
- **THEN** the report includes only receiving rows whose related purchase belongs to the active setting

### Requirement: Purchase delivery row grain
The report SHALL aggregate receiving rows by supplier, product, product code, unit, and received amount context while preserving approved receiving quantities from purchase receiving details.

#### Scenario: Multiple receiving details for same supplier product aggregate
- **WHEN** two approved receiving details in the selected period belong to the same supplier and purchase product context
- **THEN** the report displays their combined received quantity and combined amount in the supplier group

#### Scenario: Different suppliers remain separate
- **WHEN** two approved receiving details have the same product but belong to different suppliers
- **THEN** the report displays them under separate supplier groups

#### Scenario: Missing product code displays safely
- **WHEN** a purchase detail has no product code
- **THEN** the report displays an empty value or `-` for product code
- **AND** the system MUST NOT fabricate product code data

### Requirement: Purchase delivery amount calculation
The report SHALL calculate `Jumlah` from persisted purchase detail commercial values prorated by approved received quantity.

#### Scenario: Partial receiving amount is prorated
- **WHEN** a purchase detail has quantity 10 and persisted commercial line amount 1,000,000
- **AND** an approved receiving detail receives quantity 4 for that purchase detail
- **THEN** the report shows quantity 4 and amount 400,000

#### Scenario: Multiple receiving notes do not double count full purchase line
- **WHEN** a purchase detail with amount 1,000,000 is received in two approved receiving notes with quantities 3 and 2
- **THEN** the report amount for those receiving details is based on quantities 3 and 2
- **AND** the report does not count 1,000,000 for each receiving note

#### Scenario: Currency amounts are rounded for display and export
- **WHEN** calculated amount contains a floating precision artifact
- **THEN** the report displays and exports the amount rounded to two currency decimals

#### Scenario: Zero purchase quantity is guarded
- **WHEN** a receiving detail links to a purchase detail whose purchase quantity is zero or missing
- **THEN** the report does not divide by zero
- **AND** the report displays zero amount for that row unless a safe persisted amount can be derived

### Requirement: Purchase delivery columns and totals
The system SHALL present the purchase delivery report with sample-aligned Bahasa Indonesia columns and supplier/group totals.

#### Scenario: Report displays required columns
- **WHEN** a user runs the `Pengiriman pembelian` report
- **THEN** the table includes `Supplier & Kode produk / SKU`
- **AND** the table includes `Nama produk`
- **AND** the table includes `Unit`
- **AND** the table includes `Qty`
- **AND** the table includes `Jumlah`

#### Scenario: Supplier subtotal is shown
- **WHEN** a supplier group has one or more matching receiving rows
- **THEN** the report shows a subtotal equal to that supplier group's row amounts

#### Scenario: Grand total is shown
- **WHEN** the report has one or more matching receiving rows
- **THEN** the report shows a grand total equal to all supplier subtotals

#### Scenario: Empty result state is shown
- **WHEN** filters match no approved receiving details
- **THEN** the report shows an empty state instead of totals

### Requirement: Purchase delivery filters and sorting
The report SHALL support date range, period presets, supplier, tag, and product category filters, with configurable tag and category match logic, and sorting by supplier, purchase delivery, or product.

#### Scenario: Date range filters receiving date
- **WHEN** a user applies `Tanggal awal` and `Tanggal akhir`
- **THEN** the report includes approved receiving rows whose receiving note date is inside the selected range
- **AND** the report excludes approved receiving rows whose receiving note date is outside the selected range

#### Scenario: Supplier filter narrows rows
- **WHEN** the user selects one or more suppliers and applies filters
- **THEN** only approved receiving rows for purchases belonging to those suppliers are shown

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with `Mencakup semua`
- **THEN** only receiving rows whose related purchase contains every selected tag are included

#### Scenario: Tag any-match logic
- **WHEN** the user selects multiple tags with `Salah satu`
- **THEN** receiving rows whose related purchase contains at least one selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with `Salah satu`
- **THEN** receiving rows for products in at least one selected category are included

#### Scenario: Sort by purchase delivery
- **WHEN** the user sorts by `Pengiriman pembelian`
- **THEN** rows are ordered by receiving date within the report's grouped output according to the selected sort direction

#### Scenario: Sort by product
- **WHEN** the user sorts by `Produk`
- **THEN** rows are ordered by product display name within the report's grouped output according to the selected sort direction

### Requirement: Snapshot-validated purchase delivery exports
The report SHALL export Excel and CSV only when the current filters match the last successfully applied filter snapshot.

#### Scenario: Export blocked before applying filters
- **WHEN** the user attempts to export before applying filters
- **THEN** the system refuses the export and asks the user to apply filters first

#### Scenario: Export matches current report rows
- **WHEN** the user exports after applying filters
- **THEN** the exported rows, supplier subtotals, and grand total match the report data for those filters

#### Scenario: Export blocked after filter changes
- **WHEN** the user changes filters after applying them
- **THEN** the system refuses export until the filters are applied again

#### Scenario: Export is not limited to current page
- **WHEN** a filtered report has more rows than the current page displays
- **THEN** Excel and CSV exports include every row matching the applied filters

### Requirement: Purchase delivery report is read-only
The purchase delivery report SHALL NOT create, update, delete, approve, reject, receive, or archive purchase or stock records.

#### Scenario: Implementation does not require schema migration
- **WHEN** the purchase delivery report is implemented
- **THEN** no database migration is required for the report

#### Scenario: Report viewing does not mutate receiving data
- **WHEN** a user applies filters, paginates, sorts, or exports the report
- **THEN** existing purchase, receiving, stock, serial, payment, and product records remain unchanged
