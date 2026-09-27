## ADDED Requirements

### Requirement: Inventory detail report loads product details on demand

The system SHALL render `Detail persediaan barang` initially as product summaries without hydrating every transaction row for every filtered product. Each product summary SHALL show product identity, opening stock, period stock increases, period stock decreases, ending stock, and unit for the active filters. Transaction rows SHALL be loaded only for a product the user expands.

#### Scenario: Initial report shows product summaries

- **WHEN** a user applies inventory detail filters
- **THEN** the report shows matching product summaries with opening stock, period movement, ending stock, and unit
- **AND** the initial render does not require every filtered product's transaction rows to be present in the Livewire view data

#### Scenario: Expanding a product loads only that product

- **WHEN** a user expands a product in `Detail persediaan barang`
- **THEN** the system loads and displays that product's opening row, in-period transaction rows, and subtotal using the active filters
- **AND** other collapsed products remain summary-only

#### Scenario: Filter changes clear expanded product details

- **WHEN** the user changes the date range, category filter, product filter, category match mode, or sort order and reapplies filters
- **THEN** previously expanded product details are cleared
- **AND** subsequent expansions use the newly applied filters

## MODIFIED Requirements

### Requirement: Per-transaction quantity ledger rows

For each included product, the report SHALL be able to emit one ledger row per resolvable inventory transaction dated within the selected range, in chronological order. On screen, these rows SHALL be emitted when the product is expanded. In exports, these rows SHALL be emitted for every filtered product. Each row SHALL show `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Deskripsi`, `Mutasi` as signed quantity change, `Stok di gudang` as running stock after the row, and `Unit`.

#### Scenario: Purchase increases running stock

- **WHEN** a purchase transaction increases stock within the selected range and the product detail is rendered
- **THEN** a ledger row shows a positive `Mutasi`
- **AND** `Stok di gudang` shows the increased running stock

#### Scenario: Sale reduces running stock

- **WHEN** a sale or dispatch transaction decreases stock within the selected range and the product detail is rendered
- **THEN** a ledger row shows a negative `Mutasi`
- **AND** `Stok di gudang` shows the reduced running stock

#### Scenario: Adjustment uses signed stock difference

- **WHEN** a stock adjustment transaction changes stock within the selected range and the product detail is rendered
- **THEN** a ledger row shows the signed adjustment quantity
- **AND** `Stok di gudang` shows the running stock after the adjustment

#### Scenario: Transaction type is labeled for users

- **WHEN** a ledger row is rendered for purchase, sale, adjustment, or transfer activity
- **THEN** the `Tipe transaksi` column shows a user-facing transaction label consistent with the report family

#### Scenario: Chronological ordering

- **WHEN** a product has multiple transactions in the selected range and the product detail is rendered
- **THEN** ledger rows are ordered by resolved transaction date, then reference, then a stable tiebreaker

#### Scenario: Multiline descriptions are preserved

- **WHEN** a source transaction description contains line breaks
- **THEN** the UI and exports preserve the description content without corrupting adjacent columns

### Requirement: CSV and XLSX export

The report SHALL provide CSV and XLSX export of the full filtered result set across all pages. Exports SHALL honor active date, category, product, and sorting filters. Exports SHALL include full transaction detail for every filtered product regardless of which products are expanded or collapsed on screen.

#### Scenario: CSV export uses flat sample columns

- **WHEN** the user exports CSV
- **THEN** the exported file includes every filtered row across all pages
- **AND** columns are ordered as `Kode Barang`, `Barang`, `Tanggal`, `Tipe Transaksi`, `No. Transaksi`, `Deskripsi`, `Mutasi`, `Stok di Tangan`, and `Unit`
- **AND** collapsed on-screen products do not cause their transaction rows to be omitted from the export

#### Scenario: XLSX export uses grouped sample layout

- **WHEN** the user exports XLSX
- **THEN** the exported workbook includes company name, `Rincian Persediaan Barang`, selected date range, `(dalam IDR)`, the grouped detail table, and per-product `Total Stok di Tangan` subtotal rows
- **AND** collapsed on-screen products do not cause their transaction rows to be omitted from the export

#### Scenario: Export honors filters

- **WHEN** category, product, or date-range filters are active and the user exports
- **THEN** the exported file contains only rows matching those filters
