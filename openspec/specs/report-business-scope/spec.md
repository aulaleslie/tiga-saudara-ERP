## Purpose

Defines the shared rule for which businesses (settings) a report may include: the options offered, the default selection, server-side sanitizing, and how dependent filters follow the selection.

## Requirements

### Requirement: Business options are limited to the user's assignments
Every report that offers a business selector SHALL offer exactly the businesses assigned to the authenticated user, ordered by company name. A user holding the Super Admin role SHALL be offered every business. No additional permission SHALL be required beyond the report's own access permission.

#### Scenario: Assigned user sees only assigned businesses
- **WHEN** a user assigned to businesses A and B (not C) opens a business-scoped report
- **THEN** the business selector SHALL list exactly A and B

#### Scenario: Super Admin sees all businesses
- **WHEN** a Super Admin opens a business-scoped report
- **THEN** the business selector SHALL list every business

#### Scenario: Existing scoped reports follow the same rule
- **WHEN** a user assigned to A and B opens Penjualan per produk, Laba Rugi, or an operational GL/TB/CF/BS report
- **THEN** the company scope selector SHALL list only A and B

### Requirement: Submitted business IDs are sanitized on the server
Before generating, rendering, or exporting a report, the system SHALL discard every submitted business identifier that is not among the user's available businesses, and SHALL never disclose data for a discarded business.

#### Scenario: Tampered business ID is dropped
- **WHEN** a user assigned to A and B submits a selection containing A and C
- **THEN** the report SHALL include only data for A
- **AND** no row, total, option, or export value SHALL reflect business C

### Requirement: Default selection is the session business
A business-scoped report SHALL pre-select only the active session business when it first loads, unless the report's own requirements define a different default.

#### Scenario: First load
- **WHEN** a user opens a business-scoped report with active session business A
- **THEN** only A SHALL be selected

### Requirement: Empty selection does not run the report
When no valid business remains selected in a business-scoped Produk report, the report SHALL NOT query data. It SHALL show the message `Pilih minimal satu bisnis`, and export SHALL be unavailable. In sales and finance reports (Penjualan per produk, Laba Rugi, GL/TB/CF/BS), an empty selection SHALL fall back to the active session business (or the first assigned business if the session business is not assigned).

#### Scenario: User deselects every business on a product report
- **WHEN** the user clears the business selection on a business-scoped Produk report and applies filters
- **THEN** the report SHALL show `Pilih minimal satu bisnis`
- **AND** no report rows SHALL be shown

#### Scenario: Empty selection fallback on sales and finance reports
- **WHEN** the user submits an empty business selection on a sales or finance report
- **THEN** the report SHALL fall back to the active session business if assigned to the user
- **AND** if the session business is not assigned, it SHALL fall back to the user's first assigned business

### Requirement: Dependent filter options follow the selected businesses
Location/warehouse options SHALL be the union of the locations of the selected businesses, each labelled with its business name when more than one business is selected. Category and product options SHALL come from the global catalog. When a business is deselected, the system SHALL remove any selected location that belongs to it.

#### Scenario: Warehouse options across two businesses
- **WHEN** businesses A and B are selected
- **THEN** the warehouse filter SHALL list the locations of A and B, labelled like `Gudang Utama — A`

#### Scenario: Deselecting a business prunes its locations
- **WHEN** a location of B is selected and the user deselects B
- **THEN** that location SHALL be removed from the selection

### Requirement: Product population is independent of product ownership
Business-scoped product reports SHALL NOT filter products or categories by their owning business. A product SHALL be eligible when it has stock or inventory movements in a location of a selected business, whichever business owns it.

#### Scenario: Product owned by another business
- **WHEN** a product owned by business B has stock in a location of business A, and only A is selected
- **THEN** the product SHALL appear in the report with A's stock

### Requirement: Single-business output is unchanged
When exactly one business is selected, a business-scoped report SHALL render and export the same columns and layout as before business selection was introduced, without per-business column groups or a Bisnis column.

#### Scenario: Default view
- **WHEN** a report runs with only the session business selected
- **THEN** its on-screen columns and export columns SHALL match the pre-change layout

### Requirement: Per-business valuation and totals
Where a report shows inventory value, each business's value SHALL use that business's own average cost. Any cross-business total value SHALL equal the sum of the per-business values.

#### Scenario: Total equals sum of business values
- **WHEN** a product has 10 units at average cost 100 in A and 5 units at average cost 200 in B, and both are selected
- **THEN** A's value SHALL be 1,000, B's value SHALL be 1,000, and the total value SHALL be 2,000

### Requirement: Business selector UI maintains fixed dimensions and independent state
The business selector in the 7 Produk reports SHALL render inside a fixed-width container (`.report-business-slot`, 260px on desktop viewports) with a fixed-height trigger (38px). The selector SHALL truncate labels on a single line so selecting one, several, or all businesses does not push or shift neighboring filter controls. Closing or cancelling the secondary filter drawer SHALL NOT alter or revert the selected businesses.

#### Scenario: Selection change does not shift layout
- **WHEN** the user selects or deselects businesses in the checkbox dropdown
- **THEN** the selector container SHALL maintain a fixed width of 260px and trigger height of 38px
- **AND** neighboring action buttons or date pickers SHALL NOT shift position

#### Scenario: Closing secondary filter drawer preserves business selection
- **WHEN** the user modifies the business selection and then opens and cancels or closes the secondary filter drawer
- **THEN** the report's selected businesses SHALL NOT revert to previous applied filter values

#### Scenario: Menu opens as an overlay
- **WHEN** the user opens the business checkbox dropdown menu
- **THEN** the menu SHALL display as an absolute overlay
- **AND** opening or scrolling the menu SHALL NOT push or move other filter controls on the page

#### Scenario: Select all selects every assigned business
- **WHEN** the user clicks "Pilih semua" in the business checkbox dropdown
- **THEN** all available assigned businesses SHALL be selected in the pending selection

#### Scenario: Clear selection unchecks every business
- **WHEN** the user clicks "Kosongkan" in the business checkbox dropdown
- **THEN** all businesses SHALL be deselected in the pending selection

#### Scenario: Search box appears only when options exceed six
- **WHEN** the available businesses list has more than 6 items
- **THEN** an inline search input SHALL be displayed at the top of the menu
- **AND** when there are 6 or fewer businesses, the search input SHALL NOT be displayed

### Requirement: Indonesian transaction labels and conditions
Every transaction type and condition displayed in product reports, ledgers, exports, and dialogs SHALL be rendered using humanized Indonesian labels, and SHALL NEVER expose raw internal enum constants (such as `SALE_RETURN_GOOD_NON_TAX` or `good`).

#### Scenario: Transaction type display
- **WHEN** an inventory ledger, detail, valuation, or mutation report displays transaction records
- **THEN** every transaction type SHALL be translated to its Indonesian label (e.g. `SALE_RETURN_GOOD_NON_TAX` displayed as `Retur Penjualan Barang Baik (Non Pajak)`)
- **AND** fallback unknown types SHALL display as `Mutasi Stok`

#### Scenario: Serial dialog condition display
- **WHEN** the Lintas Bisnis serial dialog displays stock condition
- **THEN** it SHALL display `Bagus (Siap Jual)` or `Rusak` instead of raw keys
