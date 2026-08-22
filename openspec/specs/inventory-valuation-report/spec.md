## Purpose

The `Nilai Persediaan Barang` (Inventory Valuation) report allows authorized users to inspect per-product inventory valuation within a selected date range using weighted-average cost replayed from purchase transactions. The report displays product opening balances and values, per-transaction valuation ledger rows showing running cost and value, and subtotals grouped by product, supporting category and product filtering, CSV and XLSX export, and on-demand loading of valuation details to keep initial page loads performant.
## Requirements
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

### Requirement: Company-owned inventory valuation excludes supplier-owned consignment custody
The Inventory Valuation report SHALL identify consignment receipt and reversal movements and SHALL exclude supplier-owned consignment quantity and value from company-owned inventory totals while preserving physical consignment evidence in a clearly separated view or classification.

#### Scenario: Consignment-only product is received
- **WHEN** a product has only approved consignment stock in the active setting
- **THEN** company-owned ending quantity and value SHALL exclude that consignment stock
- **AND** the report SHALL NOT present its operational average cost as company-owned inventory value

#### Scenario: Product has owned and consignment stock
- **WHEN** a product has both ordinary owned stock and supplier-owned consignment stock
- **THEN** company-owned valuation SHALL include only the owned quantity/value
- **AND** consignment quantity/value SHALL be distinguishable without double counting

#### Scenario: Consignment receipt is reversed
- **WHEN** an approved consignment receipt is fully reversed within the report period
- **THEN** consignment custody quantity/value SHALL return to its pre-receipt result
- **AND** company-owned totals SHALL remain unaffected

#### Scenario: Existing standard valuation is preserved
- **WHEN** the report contains only standard inventory activity
- **THEN** existing filters, weighted-average replay, summaries, details, pagination, and exports SHALL retain their behavior

