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
- **THEN** the report displays the product without a code, aligned with the product name field

#### Scenario: Zero-quantity receiving details are excluded
- **WHEN** a receiving detail has a received quantity of zero
- **THEN** the detail is not included in the report table, grouped subtotals, or grand totals

### Requirement: Purchase delivery filtering and sorting
The report SHALL support filtering by receiving date range, period preset, suppliers, purchase tags with Salah satu / Mencakup semua logic, and product categories with Salah satu / Mencakup semua logic. The report SHALL support sorting by supplier, receiving date, and product name, and sorting order SHALL be stable when grouped by supplier.

#### Scenario: Date range filtering works as expected
- **WHEN** a user selects a date range and applies it
- **THEN** only receiving notes with a date in that range are included

#### Scenario: Period presets work as expected
- **WHEN** a user selects a period preset (today, week, month, quarter, year, previous period)
- **THEN** the report is filtered to that period and the date range fields reflect the preset

#### Scenario: Supplier filter matches any supplier in the list
- **WHEN** a user selects multiple suppliers with Salah satu logic
- **THEN** the report includes only rows from those suppliers

#### Scenario: Tag filter with Salah satu includes rows from any matching tag
- **WHEN** a user selects multiple purchase tags with Salah satu logic
- **THEN** the report includes rows from purchases with any of those tags

#### Scenario: Tag filter with Mencakup semua includes rows from purchases with all selected tags
- **WHEN** a user selects multiple purchase tags with Mencakup semua logic
- **THEN** the report includes rows from purchases that have all selected tags

#### Scenario: Category filter with Salah satu includes rows with any matching category
- **WHEN** a user selects multiple product categories with Salah satu logic
- **THEN** the report includes rows with products in any of those categories

#### Scenario: Category filter with Mencakup semua includes rows with products in all selected categories
- **WHEN** a user selects multiple product categories with Mencakup semua logic
- **THEN** the report includes rows with products that are in all selected categories

#### Scenario: Sort by supplier groups rows by supplier
- **WHEN** a user sorts by supplier
- **THEN** rows are grouped and sorted by supplier name

#### Scenario: Sort by receiving date orders groups chronologically
- **WHEN** a user sorts by receiving date
- **THEN** supplier groups are sorted by the earliest receiving date in each group

#### Scenario: Sort by product name orders within supplier groups
- **WHEN** a user sorts by product name
- **THEN** rows within each supplier group are sorted by product name

### Requirement: Purchase delivery report display
The report SHALL display supplier groups with subtotals, a grand total, and proper handling of pagination splits. When pagination splits a supplier group, the supplier name and any subtotal information SHALL be repeated on the following page.

#### Scenario: Report displays supplier header and subtotal
- **WHEN** the report displays a supplier group
- **THEN** the supplier name is shown at the top of the group
- **AND** the supplier subtotal (total quantity and total amount for that supplier) is shown after the group's rows

#### Scenario: Grand total is shown after all rows
- **WHEN** the report displays all filtered rows on the last page
- **THEN** the grand total (total quantity and total amount across all suppliers) is shown

#### Scenario: Pagination splits supplier group correctly
- **WHEN** pagination splits a supplier group across pages
- **THEN** the supplier name is repeated on the next page
- **AND** subtotal calculations remain correct

#### Scenario: Empty state is shown when no rows match filters
- **WHEN** no receiving notes match the applied filters
- **THEN** the report displays an empty state message in Bahasa Indonesia

### Requirement: Purchase delivery export
The report SHALL support export to Excel and CSV, including all matching rows (not just the current page), with subtotals and grand totals matching the on-screen report.

#### Scenario: Export includes all rows
- **WHEN** a user exports the report
- **THEN** the export includes every matching row, regardless of pagination

#### Scenario: Export includes subtotals and grand total
- **WHEN** a user exports the report
- **THEN** the export includes supplier subtotals and a grand total matching the on-screen report

#### Scenario: Export blocks when filters are pending
- **WHEN** pending filters differ from the applied filters
- **THEN** the export button is disabled

#### Scenario: Export blocks when filters have not been applied
- **WHEN** no filters have been applied yet
- **THEN** the export button is disabled
