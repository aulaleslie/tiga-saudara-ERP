## ADDED Requirements

### Requirement: Warehouse stock valuation report access
The system SHALL provide a `Nilai stok gudang` report page under Reports > Produk for authorized users, scoped to the authenticated user's active setting.

#### Scenario: Authorized user opens report
- **WHEN** a user with `inventoryValuationReports.access` opens the warehouse stock valuation report route
- **THEN** the system SHALL render the `Nilai stok gudang` report page
- **AND** the page title SHALL include `(dalam IDR)`.

#### Scenario: Unauthorized user denied
- **WHEN** a user without `inventoryValuationReports.access` opens the warehouse stock valuation report route
- **THEN** the system SHALL deny access.

#### Scenario: Reports landing card is actionable
- **WHEN** a user with `inventoryValuationReports.access` views the Reports > Produk landing cards
- **THEN** the `Nilai stok gudang` card SHALL link to the warehouse stock valuation report route instead of rendering as a placeholder.

#### Scenario: Data is scoped to active setting
- **WHEN** the report renders or exports
- **THEN** products, warehouses, categories, prices, and stock movements outside the active `setting_id` SHALL be excluded.

### Requirement: Report filters
The system SHALL provide sample-aligned filters for as-of date, period preset, warehouse selection, product stock status, product category, category match mode, and warehouse name ordering.

#### Scenario: Default filter state
- **WHEN** an authorized user first opens the report
- **THEN** the system SHALL default the as-of date to the current date
- **AND** the system SHALL include active-setting warehouse locations as eligible warehouse filter options.

#### Scenario: Period preset updates as-of date
- **WHEN** the user selects `Hari ini`, `Pekan ini`, `Bulan ini`, `Kuartal ini`, `Tahun ini`, `Kemarin`, `Pekan lalu`, `Bulan lalu`, `Kuartal lalu`, `Tahun lalu`, or `Custom`
- **THEN** the system SHALL update or preserve the as-of date according to the selected preset.

#### Scenario: Warehouse filter limits warehouse groups
- **WHEN** the user selects one or more warehouses and applies the filters
- **THEN** the report SHALL include valuation rows only for the selected warehouses.

#### Scenario: Product stock status filter
- **WHEN** the user selects a product status filter and applies the filters
- **THEN** the report SHALL include only rows matching `Semua produk`, `Hanya produk dengan stok habis`, `Hanya produk dengan stok tersedia`, or `Hanya produk dengan stok di bawah batas minimum` according to the selected status.

#### Scenario: Category filter with match mode
- **WHEN** the user selects one or more product categories and chooses `Mencakup semua` or `Salah satu`
- **THEN** the report SHALL include only products matching the selected category filter and match mode.

#### Scenario: Warehouse name ordering
- **WHEN** the user selects warehouse name order `A-Z` or `Z-A`
- **THEN** the report SHALL order warehouse groups by warehouse name in the selected direction.

#### Scenario: No warehouse in active setting
- **WHEN** the active setting has no warehouse locations available for the report
- **THEN** the report SHALL render an empty state without using warehouses from another setting.

### Requirement: As-of warehouse valuation calculation
The system SHALL calculate product stock valuation per selected warehouse as of the selected date using warehouse quantity multiplied by product average cost.

#### Scenario: Product has location stock before selected date
- **WHEN** a stock-managed product has stock movements for a selected warehouse on or before the as-of date
- **THEN** the report SHALL show the ending quantity for that product and warehouse as of the selected date.

#### Scenario: Future stock movement excluded
- **WHEN** a stock movement occurs after the selected as-of date
- **THEN** the report SHALL exclude that movement from displayed quantity and stock value.

#### Scenario: Average cost source
- **WHEN** a product has an active-setting average purchase price row
- **THEN** the report SHALL use that average purchase price as `Harga rata-rata`.

#### Scenario: Average cost fallback
- **WHEN** a product has no active-setting average purchase price row
- **THEN** the report SHALL use the existing product-level average purchase price fallback when available
- **AND** the report SHALL use `0` when no average cost source exists.

#### Scenario: Stock value calculation
- **WHEN** a report row has quantity and average cost
- **THEN** `Nilai stok` SHALL equal quantity multiplied by average cost.

#### Scenario: Zero quantity retained
- **WHEN** a product has zero quantity in a selected warehouse
- **THEN** the report SHALL include `0` for warehouse stock and stock value.

#### Scenario: Negative quantity retained
- **WHEN** a product has negative quantity in a selected warehouse
- **THEN** the report SHALL include the negative quantity and calculated negative stock value without clamping it to zero.

#### Scenario: Non-stock-managed product excluded
- **WHEN** a product is not stock-managed
- **THEN** the report SHALL exclude that product.

### Requirement: Report table presentation
The system SHALL render a paginated warehouse-grouped report table with sample-aligned product, stock, minimum stock, unit, average cost, stock value, and total value presentation.

#### Scenario: Table columns render
- **WHEN** report rows are available
- **THEN** the table SHALL include `Kode produk / SKU`, `Nama produk`, `Stok di gudang`, `Batas min.`, `Unit`, `Harga rata-rata`, and `Nilai stok`.

#### Scenario: Warehouse group renders
- **WHEN** report rows are available for a warehouse
- **THEN** the table SHALL show a warehouse group label before that warehouse's product rows.

#### Scenario: Minimum stock displayed
- **WHEN** a product row is rendered
- **THEN** `Batas min.` SHALL display the product's configured minimum stock threshold or `0` when none is configured.

#### Scenario: Nullable product code display
- **WHEN** a product has no product code
- **THEN** the UI SHALL display a blank or `-` in the product code column without hiding the row.

#### Scenario: Grand total displayed
- **WHEN** the report has one or more rows
- **THEN** the table SHALL display a total stock value row that sums `Nilai stok` across all matching rows and all pages.

#### Scenario: Product rows paginated
- **WHEN** the report has more rows than the selected page size
- **THEN** the system SHALL paginate rows and show the current displayed range and total row count.

#### Scenario: Average cost note displayed
- **WHEN** the report renders
- **THEN** the report SHALL show a note that inventory value is calculated using the average cost method.

### Requirement: CSV export
The system SHALL export the warehouse stock valuation report to CSV using the sample flat row shape and the applied filters.

#### Scenario: CSV header shape
- **WHEN** an authorized user exports the report as CSV
- **THEN** the CSV SHALL start with `Gudang`, `Kode Produk`, `Nama Produk`, `Qty`, `Min. Qty`, `Satuan Produk`, `Harga Rata-rata`, and `Nilai Persediaan`.

#### Scenario: CSV row shape
- **WHEN** the CSV contains a product row
- **THEN** the row SHALL contain warehouse name, product code, product name, quantity, minimum quantity, product unit, average cost, and stock value.

#### Scenario: CSV row for product without code
- **WHEN** the CSV contains a product without a product code
- **THEN** the `Kode Produk` cell SHALL be blank.

#### Scenario: CSV respects filters
- **WHEN** the user exports after applying date, warehouse, stock status, or category filters
- **THEN** the CSV SHALL contain only rows matching the applied filters.

#### Scenario: CSV omits presentation metadata
- **WHEN** the user exports CSV
- **THEN** the CSV SHALL NOT include title rows, date metadata rows, warehouse group-only rows, or a total row.

### Requirement: XLSX export
The system SHALL export the warehouse stock valuation report to XLSX using the sample metadata, grouping, table, and total shape.

#### Scenario: XLSX metadata rows
- **WHEN** an authorized user exports the report as XLSX
- **THEN** the workbook SHALL include title metadata `Nilai Stok Gudang` and the selected as-of date before the table header.

#### Scenario: XLSX table header shape
- **WHEN** the workbook contains the table header
- **THEN** the header SHALL include `Gudang/Kode Produk`, `Nama Produk`, `Qty`, `Min. Qty`, `Satuan Produk`, `Harga Rata-rata`, and `Nilai Persediaan`.

#### Scenario: XLSX warehouse grouping
- **WHEN** the workbook contains rows for a warehouse
- **THEN** the workbook SHALL include a warehouse group row followed by product rows for that warehouse.

#### Scenario: XLSX total row
- **WHEN** the workbook contains report rows
- **THEN** the workbook SHALL include a final `Total` row with the grand stock value total.

#### Scenario: XLSX respects filters
- **WHEN** the user exports after applying date, warehouse, stock status, or category filters
- **THEN** the XLSX SHALL contain only rows and warehouse groups matching the applied filters.

#### Scenario: XLSX total is exported value
- **WHEN** the workbook includes a total row
- **THEN** the total cell SHALL contain the computed report total value and SHALL NOT require a spreadsheet formula to calculate it.

### Requirement: Report boundaries
The warehouse stock valuation report SHALL be read-only and SHALL NOT change existing stock, product, transaction, POS, Sales, Purchase, or import behavior.

#### Scenario: Read-only render
- **WHEN** a user views the report
- **THEN** the system SHALL NOT create products, update product stock, update product prices, or create stock transactions.

#### Scenario: Read-only export
- **WHEN** a user exports the report
- **THEN** the system SHALL NOT create products, update product stock, update product prices, or create stock transactions.

#### Scenario: Existing reports preserved
- **WHEN** users open the existing inventory valuation report or warehouse stock quantity report
- **THEN** those reports SHALL preserve their existing route, permission, filter, table, and export behavior.
