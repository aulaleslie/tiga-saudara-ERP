## ADDED Requirements

### Requirement: Inventory valuation report access

The system SHALL provide an Inventory Valuation ("Nilai Persediaan Barang") report at the route `reports.inventory-valuation-report.index`, gated by the `inventoryValuationReports.access` permission, scoped to the authenticated user's active setting.

#### Scenario: Permitted user opens the report

- **WHEN** a user holding `inventoryValuationReports.access` requests `reports.inventory-valuation-report.index`
- **THEN** the system renders the Inventory Valuation report for the user's active setting
- **AND** the page title is "Nilai Persediaan Barang" with a "(dalam IDR)" currency note

#### Scenario: Unpermitted user is denied

- **WHEN** a user without `inventoryValuationReports.access` requests `reports.inventory-valuation-report.index`
- **THEN** the system denies access

#### Scenario: Data is scoped to the active setting

- **WHEN** the report renders
- **THEN** only products and transactions belonging to the user's active `setting_id` are included

### Requirement: Date range and period filters

The report SHALL accept a start date (Tanggal awal) and end date (Tanggal akhir) and SHALL provide period presets that populate the range: Hari ini, Pekan ini, Bulan ini, Kuartal ini, Tahun ini, Kemarin, Pekan lalu, Bulan lalu, Kuartal lalu, Tahun lalu, and Custom. The end date SHALL be inclusive through end of day.

#### Scenario: Default range

- **WHEN** the report is first opened with no explicit range
- **THEN** a sensible default range is applied (the current month) and reflected in the date inputs

#### Scenario: Selecting a period preset

- **WHEN** the user selects a period preset such as "Bulan ini"
- **THEN** the start and end date inputs are set to that period's boundaries
- **AND** the report recomputes for that range when applied

#### Scenario: End date is inclusive

- **WHEN** a transaction occurs at any time on the selected end date
- **THEN** that transaction is included in the ledger

### Requirement: Category and product filters

The report SHALL provide a multi-select product category filter with two match modes — "Mencakup semua" (all) and "Salah satu" (any) — and a multi-select product filter. When category IDs are supplied, only products matching the selected categories under the chosen match mode SHALL be included. When product IDs are supplied, only those products SHALL be included.

#### Scenario: Category match mode "any"

- **WHEN** the user selects categories A and B with match mode "Salah satu" (any)
- **THEN** products belonging to category A or B are included

#### Scenario: Category match mode "all"

- **WHEN** the user selects categories A and B with match mode "Mencakup semua" (all)
- **THEN** only products belonging to every selected category are included

#### Scenario: Product filter narrows results

- **WHEN** the user selects specific products
- **THEN** only those products appear in the report, subject to any category filter

### Requirement: Per-product opening balance row

For each included product, the report SHALL emit an opening-balance row labeled "Saldo Awal" that reflects the product's running stock and weighted-average cost computed from all inventory activity dated strictly before the start date. The opening row's stock SHALL be shown as "Stok di gudang", its average cost as "Harga rata-rata", and its value as stock multiplied by average cost.

#### Scenario: Opening balance reflects prior activity

- **WHEN** a product had net stock and an established average cost before the start date
- **THEN** the "Saldo Awal" row shows that running stock, that average cost, and value = stock × average cost
- **AND** the "Mutasi" cell for the opening row is empty or "-"

#### Scenario: No prior activity

- **WHEN** a product had no activity before the start date
- **THEN** the "Saldo Awal" row shows zero stock and value, using the product's fallback average cost where available

### Requirement: Per-transaction ledger rows with running valuation

For each included product, after the opening row, the report SHALL emit one ledger row per inventory transaction dated within the selected range, in chronological order, each showing: Tanggal, Tipe transaksi, No. transaksi, Deskripsi, Mutasi (signed quantity change), Stok di gudang (running stock after the row), Unit, Harga rata-rata (running weighted-average cost after the row), Harga beli/jual (the transaction's buy or sell unit price), and Nilai (running stock × running average cost). Running stock and weighted-average cost SHALL be carried forward from the opening balance using the same recurrence as the existing inventory replay engine: average cost updates only on stock-increasing purchase transactions, and decreases reduce stock at the current average.

#### Scenario: Purchase updates running average cost

- **WHEN** a purchase transaction increases stock within the range
- **THEN** a ledger row shows a positive Mutasi, the increased running stock, a recomputed weighted-average cost, and the new running value

#### Scenario: Sale reduces stock at current average

- **WHEN** a sale or dispatch transaction decreases stock within the range
- **THEN** a ledger row shows a negative Mutasi, the reduced running stock, the unchanged running average cost, and the recomputed running value

#### Scenario: Transaction type is labeled in Indonesian

- **WHEN** a ledger row is rendered for a purchase, sale, adjustment, or transfer transaction
- **THEN** the "Tipe transaksi" column shows the corresponding Indonesian label (e.g. Pembelian, Penjualan, Penyesuaian, Transfer)

#### Scenario: Chronological ordering

- **WHEN** a product has multiple transactions in the range
- **THEN** ledger rows are ordered by transaction date, then by reference, then by a stable tiebreaker

### Requirement: Per-product subtotals and grand total

After each product's ledger rows, the report SHALL render a product subtotal showing "Total stok di gudang" (the product's final running stock and unit) and "Subtotal nilai" (the product's final running value). The report SHALL render a grand "Total nilai" that sums every product's final value across all pages.

#### Scenario: Product subtotal

- **WHEN** a product's ledger ends within the range
- **THEN** a subtotal row shows the final running stock with unit and the final running value

#### Scenario: Grand total spans all pages

- **WHEN** the result set is paginated
- **THEN** the "Total nilai" reflects the sum of all products' final values across every page, not just the current page

### Requirement: Pagination and grouping

The report SHALL group rows by product and SHALL paginate by product group so that a product's opening row, ledger rows, and subtotal stay together. Grouping SHALL keep products in a stable sorted order (by product name by default).

#### Scenario: A product group is not split across pages

- **WHEN** the report paginates
- **THEN** each product's opening row, ledger rows, and subtotal appear together on the same page

### Requirement: CSV and XLSX export

The report SHALL provide CSV and XLSX export of the full filtered result set across all pages, using the column order Kode Barang, Barang, Tanggal, Tipe Transaksi, No. Transaksi, Deskripsi, Mutasi, Stok di Tangan, Unit, Harga Rata-Rata, Harga Beli/Jual, Nilai. The export SHALL include each product's opening and ledger rows, per-product subtotals, and the grand total, and SHALL honor all active filters.

#### Scenario: Export includes all pages

- **WHEN** the user exports while the on-screen table is paginated
- **THEN** the exported file contains every product's rows across all pages, not only the current page

#### Scenario: Export honors filters

- **WHEN** category, product, or date-range filters are active and the user exports
- **THEN** the exported file contains only rows matching those filters

#### Scenario: Export includes subtotals and grand total

- **WHEN** the user exports the report
- **THEN** the file contains per-product subtotal rows and a grand total row consistent with the on-screen report
