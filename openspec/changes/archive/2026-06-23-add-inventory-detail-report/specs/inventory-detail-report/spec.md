## ADDED Requirements

### Requirement: Inventory detail report access

The system SHALL provide a `Detail persediaan barang` report at the route `reports.inventory-detail-report.index`, gated by the `stockMutationReports.access` permission, scoped to the authenticated user's active setting.

#### Scenario: Permitted user opens the report

- **WHEN** a user holding `stockMutationReports.access` requests `reports.inventory-detail-report.index`
- **THEN** the system renders the `Detail persediaan barang` report for the user's active setting
- **AND** the page title is `Detail persediaan barang`
- **AND** the page displays the `(dalam IDR)` note for parity with the provided sample

#### Scenario: Unpermitted user is denied

- **WHEN** a user without `stockMutationReports.access` requests `reports.inventory-detail-report.index`
- **THEN** the system denies access

#### Scenario: Data is scoped to the active setting

- **WHEN** the report renders
- **THEN** only stock-managed products and inventory transactions belonging to the user's active `setting_id` are included

### Requirement: Date range and period filters

The report SHALL accept a start date (`Tanggal awal`) and end date (`Tanggal akhir`) and SHALL provide period presets that populate the range: Hari ini, Pekan ini, Bulan ini, Kuartal ini, Tahun ini, Kemarin, Pekan lalu, Bulan lalu, Kuartal lalu, Tahun lalu, and Custom. The end date SHALL be inclusive through end of day.

#### Scenario: Default range

- **WHEN** the report is first opened with no explicit range
- **THEN** a sensible default range is applied and reflected in the date inputs

#### Scenario: Selecting a period preset

- **WHEN** the user selects a period preset such as `Bulan ini`
- **THEN** the start and end date inputs are set to that period's boundaries
- **AND** the report recomputes for that range when applied

#### Scenario: End date is inclusive

- **WHEN** a transaction occurs at any time on the selected end date
- **THEN** that transaction is included in the detail ledger

### Requirement: Category and product filters

The report SHALL provide a multi-select product category filter with two match modes, `Mencakup semua` (all) and `Salah satu` (any), and a multi-select product filter. When category IDs are supplied, only products matching the selected categories under the chosen match mode SHALL be included. When product IDs are supplied, only those products SHALL be included.

#### Scenario: Category match mode any

- **WHEN** the user selects categories A and B with match mode `Salah satu`
- **THEN** products belonging to category A or B are included

#### Scenario: Category match mode all

- **WHEN** the user selects categories A and B with match mode `Mencakup semua`
- **THEN** only products belonging to every selected category are included

#### Scenario: Product filter narrows results

- **WHEN** the user selects specific products
- **THEN** only those products appear in the report, subject to any category filter

### Requirement: Per-product opening balance row

For each included product, the report SHALL emit an opening-balance row labeled `Saldo Awal` that reflects the product's running stock computed from all resolvable inventory activity dated strictly before the selected start date. The opening row SHALL show empty or `-` values for transaction number, description, and mutation.

#### Scenario: Opening balance reflects prior activity

- **WHEN** a product had net stock before the start date
- **THEN** the `Saldo Awal` row shows that running stock and the product unit
- **AND** the `Mutasi` cell is empty or `-`

#### Scenario: No prior activity

- **WHEN** a product had no activity before the start date
- **THEN** the `Saldo Awal` row shows zero stock and the product unit

### Requirement: Per-transaction quantity ledger rows

For each included product, after the opening row, the report SHALL emit one ledger row per resolvable inventory transaction dated within the selected range, in chronological order. Each row SHALL show `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Deskripsi`, `Mutasi` as signed quantity change, `Stok di gudang` as running stock after the row, and `Unit`.

#### Scenario: Purchase increases running stock

- **WHEN** a purchase transaction increases stock within the selected range
- **THEN** a ledger row shows a positive `Mutasi`
- **AND** `Stok di gudang` shows the increased running stock

#### Scenario: Sale reduces running stock

- **WHEN** a sale or dispatch transaction decreases stock within the selected range
- **THEN** a ledger row shows a negative `Mutasi`
- **AND** `Stok di gudang` shows the reduced running stock

#### Scenario: Adjustment uses signed stock difference

- **WHEN** a stock adjustment transaction changes stock within the selected range
- **THEN** a ledger row shows the signed adjustment quantity
- **AND** `Stok di gudang` shows the running stock after the adjustment

#### Scenario: Transaction type is labeled for users

- **WHEN** a ledger row is rendered for purchase, sale, adjustment, or transfer activity
- **THEN** the `Tipe transaksi` column shows a user-facing transaction label consistent with the report family

#### Scenario: Chronological ordering

- **WHEN** a product has multiple transactions in the selected range
- **THEN** ledger rows are ordered by resolved transaction date, then reference, then a stable tiebreaker

#### Scenario: Multiline descriptions are preserved

- **WHEN** a source transaction description contains line breaks
- **THEN** the UI and exports preserve the description content without corrupting adjacent columns

### Requirement: Per-product subtotal and grouped pagination

The report SHALL group rows by product and SHALL paginate by product group so that a product's header, opening row, ledger rows, and subtotal stay together. After each product's ledger rows, the report SHALL render a subtotal showing `Total Stok di Tangan` with the product's final running stock and unit.

#### Scenario: Product subtotal

- **WHEN** a product's ledger ends within the selected range
- **THEN** a subtotal row shows the product's final running stock and unit

#### Scenario: Product group is not split across pages

- **WHEN** the report paginates
- **THEN** each product's header, opening row, ledger rows, and subtotal appear together on the same page

#### Scenario: Blank product code is supported

- **WHEN** a product has no product code
- **THEN** the report still groups, displays, and exports the product by its product identity and name

### Requirement: CSV and XLSX export

The report SHALL provide CSV and XLSX export of the full filtered result set across all pages. Exports SHALL honor active date, category, product, and sorting filters.

#### Scenario: CSV export uses flat sample columns

- **WHEN** the user exports CSV
- **THEN** the exported file includes every filtered row across all pages
- **AND** columns are ordered as `Kode Barang`, `Barang`, `Tanggal`, `Tipe Transaksi`, `No. Transaksi`, `Deskripsi`, `Mutasi`, `Stok di Tangan`, and `Unit`

#### Scenario: XLSX export uses grouped sample layout

- **WHEN** the user exports XLSX
- **THEN** the exported workbook includes company name, `Rincian Persediaan Barang`, selected date range, `(dalam IDR)`, the grouped detail table, and per-product `Total Stok di Tangan` subtotal rows

#### Scenario: Export honors filters

- **WHEN** category, product, or date-range filters are active and the user exports
- **THEN** the exported file contains only rows matching those filters
