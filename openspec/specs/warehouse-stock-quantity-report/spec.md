## ADDED Requirements

### Requirement: Warehouse stock quantity report access
The system SHALL provide a `Kuantitas stok gudang` report page under Reports > Produk for authorized users.

#### Scenario: Authorized user opens report
- **WHEN** a user with `stockMutationReports.access` opens the warehouse stock quantity report route
- **THEN** the system SHALL render the `Kuantitas stok gudang` report page.

#### Scenario: Unauthorized user denied
- **WHEN** a user without `stockMutationReports.access` opens the warehouse stock quantity report route
- **THEN** the system SHALL deny access.

### Requirement: Report filters
The system SHALL provide sample-aligned filters for as-of date, period preset, and warehouse selection.

#### Scenario: Default filter state
- **WHEN** an authorized user first opens the report
- **THEN** the system SHALL default the as-of date to the current date
- **AND** the system SHALL include active-setting warehouse locations as eligible warehouse columns.

#### Scenario: Period preset updates as-of date
- **WHEN** the user selects a period preset such as `Hari ini`, `Pekan ini`, `Bulan ini`, `Kuartal ini`, `Tahun ini`, `Kemarin`, `Pekan lalu`, `Bulan lalu`, `Kuartal lalu`, `Tahun lalu`, or `Custom`
- **THEN** the system SHALL update or preserve the as-of date according to the selected preset.

#### Scenario: Warehouse filter limits columns
- **WHEN** the user selects one or more warehouses and applies the filters
- **THEN** the report SHALL include quantity columns only for the selected warehouses.

#### Scenario: No warehouse in active setting
- **WHEN** the active setting has no warehouse locations available for the report
- **THEN** the report SHALL render an empty state without using warehouses from another setting.

### Requirement: As-of warehouse quantity calculation
The system SHALL calculate product stock quantities per selected warehouse as of the selected date.

#### Scenario: Product has location stock before selected date
- **WHEN** a stock-managed product has stock movements for a selected warehouse on or before the as-of date
- **THEN** the report SHALL show the ending quantity for that product and warehouse as of the selected date.

#### Scenario: Future stock movement excluded
- **WHEN** a stock movement occurs after the selected as-of date
- **THEN** the report SHALL exclude that movement from the displayed warehouse quantity.

#### Scenario: Multiple selected warehouses
- **WHEN** a product has quantities in multiple selected warehouses
- **THEN** the report SHALL show each selected warehouse quantity in its own column
- **AND** `Total stok` SHALL equal the sum of those selected warehouse quantities.

#### Scenario: Zero quantity retained
- **WHEN** a product has zero quantity in a selected warehouse
- **THEN** the report SHALL include `0` for that warehouse quantity.

#### Scenario: Negative quantity retained
- **WHEN** a product has negative quantity in a selected warehouse
- **THEN** the report SHALL include the negative quantity without clamping it to zero.

### Requirement: Report table presentation
The system SHALL render a paginated report table with sample-aligned product, warehouse quantity, total, and unit columns.

#### Scenario: Table columns render
- **WHEN** report rows are available
- **THEN** the table SHALL include `Kode produk / SKU`, `Nama produk`, one column per selected warehouse, `Total stok`, and `Unit`.

#### Scenario: Nullable product code display
- **WHEN** a product has no product code
- **THEN** the UI SHALL display `-` in the product code column.

#### Scenario: Product rows paginated
- **WHEN** the report has more rows than the selected page size
- **THEN** the system SHALL paginate rows and show the current displayed count and total row count.

#### Scenario: Product name links to product detail
- **WHEN** a report row references an existing product
- **THEN** the product name SHALL link to the product detail page where the application has an existing product detail route.

### Requirement: CSV export
The system SHALL export the warehouse stock quantity report to CSV using the sample table shape.

#### Scenario: CSV header shape
- **WHEN** an authorized user exports the report as CSV
- **THEN** the CSV SHALL start with `Product Code`, `Product Name`, one column per selected warehouse, `Total Quantity`, and `Product Unit`.

#### Scenario: CSV row shape
- **WHEN** the CSV contains a product without a product code
- **THEN** the `Product Code` cell SHALL be blank.

#### Scenario: CSV respects filters
- **WHEN** the user exports after applying an as-of date and warehouse filter
- **THEN** the CSV SHALL contain rows and warehouse columns for the applied filters.

### Requirement: XLSX export
The system SHALL export the warehouse stock quantity report to XLSX using the sample metadata and table shape.

#### Scenario: XLSX metadata rows
- **WHEN** an authorized user exports the report as XLSX
- **THEN** the workbook SHALL include metadata rows for company name, `WAREHOUSE STOCK QUANTITY`, and the selected date before the table header.

#### Scenario: XLSX table shape
- **WHEN** the workbook contains report rows
- **THEN** the table SHALL include `Product Code`, `Product Name`, one column per selected warehouse, `Total Quantity`, and `Product Unit`.

#### Scenario: XLSX respects filters
- **WHEN** the user exports after applying an as-of date and warehouse filter
- **THEN** the XLSX SHALL contain rows and warehouse columns for the applied filters.

### Requirement: Report boundaries
The warehouse stock quantity report SHALL remain quantity-only and SHALL NOT mutate stock.

#### Scenario: No valuation output
- **WHEN** the report renders or exports
- **THEN** the report SHALL NOT include average cost, stock value, subtotal value, or currency columns.

#### Scenario: Read-only report
- **WHEN** a user views or exports the report
- **THEN** the system SHALL NOT create products, update product stock, or create stock transactions.
