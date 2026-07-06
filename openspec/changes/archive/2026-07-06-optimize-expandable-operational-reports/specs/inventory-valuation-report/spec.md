## ADDED Requirements

### Requirement: Inventory valuation report loads product details on demand

The system SHALL render Inventory Valuation initially as product summaries without hydrating every valuation ledger row for every filtered product. Each product summary SHALL show product identity, opening stock and value, period stock increases, period stock decreases, ending stock, ending average cost, ending value, and unit for the active filters. Valuation ledger rows SHALL be loaded only for a product the user expands.

#### Scenario: Initial report shows valuation summaries

- **WHEN** a user applies inventory valuation filters
- **THEN** the report shows matching product summaries with opening stock/value, period movement, ending stock, average cost, ending value, and unit
- **AND** the initial render does not require every filtered product's valuation ledger rows to be present in the Livewire view data

#### Scenario: Expanding a product loads only that product valuation

- **WHEN** a user expands a product in Inventory Valuation
- **THEN** the system loads and displays that product's opening row, in-period valuation ledger rows, and subtotal using the active filters
- **AND** other collapsed products remain summary-only

#### Scenario: Filter changes clear expanded valuation details

- **WHEN** the user changes the date range, category filter, product filter, category match mode, or sort order and reapplies filters
- **THEN** previously expanded product valuation details are cleared
- **AND** subsequent expansions use the newly applied filters

### Requirement: Inventory valuation purchase cost uses line DPP

Inventory valuation replay SHALL calculate purchase unit cost from stored purchase detail line DPP. The line DPP SHALL be `sub_total - product_tax_amount` divided by the purchase detail quantity. The report SHALL NOT calculate purchase cost from `price * quantity` when `sub_total` and quantity are available. Line-level discounts SHALL NOT be subtracted a second time when stored `sub_total` already reflects the line discount.

#### Scenario: Tax-included purchase excludes tax from average cost

- **WHEN** a purchase detail has `sub_total` of 110000, `product_tax_amount` of 10000, and quantity 2
- **THEN** inventory valuation replay uses purchase unit cost 50000

#### Scenario: Discounted line is not discounted twice

- **WHEN** a purchase detail's stored `sub_total` already reflects its line discount
- **THEN** inventory valuation replay subtracts only `product_tax_amount` from `sub_total` before dividing by quantity
- **AND** it does not subtract `product_discount_amount` again

#### Scenario: Document-level purchase adjustments are excluded from inventory cost

- **WHEN** a purchase has document-level `shipping_amount` or document-level `discount_amount`
- **THEN** inventory valuation replay does not allocate those document-level amounts into product average cost
- **AND** inventory valuation uses purchase detail DPP unless a future landed-cost allocation requirement supersedes this behavior

## MODIFIED Requirements

### Requirement: Per-transaction ledger rows with running valuation

For each included product, the report SHALL be able to emit one ledger row per inventory transaction dated within the selected range, in chronological order. On screen, these rows SHALL be emitted when the product is expanded. In exports, these rows SHALL be emitted for every filtered product. Each row SHALL show: Tanggal, Tipe transaksi, No. transaksi, Deskripsi, Mutasi (signed quantity change), Stok di gudang (running stock after the row), Unit, Harga rata-rata (running weighted-average cost after the row), Harga beli/jual (the transaction's buy or sell unit price), and Nilai (running stock × running average cost). Running stock and weighted-average cost SHALL be carried forward from the opening balance using the same recurrence as the inventory replay engine: average cost updates only on stock-increasing purchase transactions, and decreases reduce stock at the current average.

#### Scenario: Purchase updates running average cost

- **WHEN** a purchase transaction increases stock within the range and the product detail is rendered
- **THEN** a ledger row shows a positive Mutasi, the increased running stock, a recomputed weighted-average cost, and the new running value

#### Scenario: Sale reduces stock at current average

- **WHEN** a sale or dispatch transaction decreases stock within the range and the product detail is rendered
- **THEN** a ledger row shows a negative Mutasi, the reduced running stock, the unchanged running average cost, and the recomputed running value

#### Scenario: Transaction type is labeled in Indonesian

- **WHEN** a ledger row is rendered for a purchase, sale, adjustment, or transfer transaction
- **THEN** the "Tipe transaksi" column shows the corresponding Indonesian label (e.g. Pembelian, Penjualan, Penyesuaian, Transfer)

#### Scenario: Chronological ordering

- **WHEN** a product has multiple transactions in the range and the product detail is rendered
- **THEN** ledger rows are ordered by transaction date, then by reference, then by a stable tiebreaker

### Requirement: CSV and XLSX export

The report SHALL provide CSV and XLSX export of the full filtered result set across all pages, using the column order Kode Barang, Barang, Tanggal, Tipe Transaksi, No. Transaksi, Deskripsi, Mutasi, Stok di Tangan, Unit, Harga Rata-Rata, Harga Beli/Jual, Nilai. The export SHALL include each product's opening and ledger rows, per-product subtotals, and the grand total, and SHALL honor all active filters. Exports SHALL include full valuation detail for every filtered product regardless of which products are expanded or collapsed on screen.

#### Scenario: Export includes all pages

- **WHEN** the user exports while the on-screen table is paginated
- **THEN** the exported file contains every product's rows across all pages, not only the current page
- **AND** collapsed on-screen products do not cause their valuation ledger rows to be omitted from the export

#### Scenario: Export honors filters

- **WHEN** category, product, or date-range filters are active and the user exports
- **THEN** the exported file contains only rows matching those filters

#### Scenario: Export includes subtotals and grand total

- **WHEN** the user exports the report
- **THEN** the file contains per-product subtotal rows and a grand total row consistent with the filtered report
