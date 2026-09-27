## ADDED Requirements

### Requirement: Inventory summary report access

The system SHALL provide a `Ringkasan persediaan barang` report page under Reports > Produk. Access to the report page, Livewire actions, and exports MUST require `inventoryValuationReports.access`.

#### Scenario: Authorized user opens report

- **WHEN** a user with `inventoryValuationReports.access` opens the inventory summary report route
- **THEN** the system renders the `Ringkasan persediaan barang` report page

#### Scenario: Unauthorized user is denied

- **WHEN** a user without `inventoryValuationReports.access` requests the inventory summary report route or export action
- **THEN** the system denies access

### Requirement: As-of inventory summary rows

The system SHALL calculate inventory summary rows as of the selected report date for stock-managed products in the active setting. Each row MUST include nullable product code, product name, stock on hand aggregated across all locations, minimum stock, product unit, average cost, and value. Value MUST equal stock on hand multiplied by average cost. Blank product codes MUST remain blank in the UI and exports.

#### Scenario: Report shows product-level as-of stock

- **WHEN** an authorized user runs the report for `21/06/2026`
- **THEN** each returned row represents one stock-managed product in the active setting
- **AND** stock on hand reflects inventory state as of the end of `21/06/2026`
- **AND** stock is aggregated across all product locations
- **AND** value equals stock on hand multiplied by average cost

#### Scenario: Blank product code remains blank

- **WHEN** a report product has no product code
- **THEN** the `Kode Produk` value is rendered and exported as blank
- **AND** the system does not substitute `-` or generated text

#### Scenario: Negative stock is included

- **WHEN** a product has negative stock on hand as of the selected date
- **THEN** the row is included in the report
- **AND** its value contributes negatively to the total value

### Requirement: Inventory summary filters

The system SHALL support the sample-defined filters: selected date, period preset, product stock status, product categories, category match mode, and products. The report MUST aggregate all locations and MUST NOT provide a warehouse selector in this change.

#### Scenario: Date and period select the as-of date

- **WHEN** a user selects a date directly or through a period preset
- **THEN** the report uses the resulting selected date as the inventory as-of date

#### Scenario: Stock status filters products

- **WHEN** a user selects `Hanya produk dengan stok tersedia`
- **THEN** the report includes only rows with stock on hand greater than zero
- **WHEN** a user selects `Hanya produk dengan stok habis`
- **THEN** the report includes only rows with stock on hand less than or equal to zero
- **WHEN** a user selects `Hanya produk dengan stok di bawah batas minimum`
- **THEN** the report includes only rows with stock on hand less than minimum stock

#### Scenario: Category filter supports match mode

- **WHEN** a user selects one or more categories and `Mencakup semua`
- **THEN** the report includes rows whose product category selection satisfies all selected categories according to the existing category model
- **WHEN** a user selects one or more categories and `Salah satu`
- **THEN** the report includes rows whose product category matches at least one selected category

#### Scenario: Product filter limits rows

- **WHEN** a user selects one or more products
- **THEN** the report includes only selected products that also satisfy the other active filters

#### Scenario: Warehouse selector is absent

- **WHEN** the inventory summary filter UI is rendered
- **THEN** no warehouse or location selector is shown
- **AND** report rows remain aggregated across all locations

### Requirement: Inventory summary sorting and pagination

The system SHALL support sorting by product name, product code/SKU, stock on hand, average cost, and value in ascending or descending order. The UI table SHALL paginate filtered rows and show a total product-row count and total value for the complete filtered dataset, not only the current page.

#### Scenario: Sort by supported column

- **WHEN** a user sorts by `Nama produk`, `Kode produk / SKU`, `Stok di gudang`, `Harga rata-rata`, or `Nilai`
- **THEN** the report orders the filtered dataset by the selected column and direction

#### Scenario: Totals use all filtered rows

- **WHEN** a filtered report spans multiple pages
- **THEN** the displayed product count reflects all filtered rows
- **AND** the displayed total value reflects all filtered rows
- **AND** pagination changes do not alter the total value

### Requirement: Inventory summary exports

The system SHALL export the complete filtered inventory summary dataset to CSV and XLSX using the active filters and sorting. CSV MUST start with the table headers. XLSX MUST include report metadata rows before the table headers: company name, `Ringkasan Persediaan Barang`, selected date formatted as `dd/mm/yyyy`, currency note, sort metadata, and a blank spacer row. Both exports MUST include the same row data and total value as the UI dataset.

#### Scenario: CSV export shape

- **WHEN** a user exports the report to CSV
- **THEN** the first row contains `Kode Produk,Nama Produk,Stok di tangan,Batas Minimum,Satuan,Harga Rata-rata,Nilai`
- **AND** no report metadata rows appear before the CSV headers
- **AND** all filtered rows are exported

#### Scenario: XLSX export shape

- **WHEN** a user exports the report to XLSX
- **THEN** the workbook includes a sheet named `Inventory Summary`
- **AND** the top metadata rows include company name, `Ringkasan Persediaan Barang`, selected date, currency note, and sort metadata
- **AND** the table headers appear after the metadata spacer row
- **AND** the workbook includes the filtered rows and total value

#### Scenario: Export parity with UI

- **WHEN** a user exports a filtered and sorted report
- **THEN** the exported rows match the same filtered and sorted dataset used by the UI
- **AND** the exported total value equals the UI total value

### Requirement: Inventory account output is deferred

The system MUST NOT expose a functional inventory-account output for `Tampilkan akun persediaan barang` until an authoritative inventory account mapping exists. If the filter control is rendered for visual parity, applying it MUST NOT add guessed account data to UI or exports.

#### Scenario: Account toggle does not produce guessed data

- **WHEN** a user applies the report with `Tampilkan akun persediaan barang` selected before account mapping is implemented
- **THEN** the report does not add guessed inventory account columns or values
- **AND** the core inventory summary rows remain unchanged
