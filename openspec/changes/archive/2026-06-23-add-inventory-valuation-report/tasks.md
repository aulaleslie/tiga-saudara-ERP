## Phase 1: Shared Replay Engine Extraction

- [x] 1.1 Extract the shared replay mechanics from `InventorySummaryReportQueryService` (delta resolution, unit-price resolution, WAC recurrence/`applyTransaction`, `extractReference`/`extractTransferId`, purchase/sale price + date map builders, transfer meta, transaction sort/`compareTransactions`, `resolveTransactionDate`) into a reusable trait or base class (e.g. `App\Services\Reports\Concerns\InventoryReplaySupport`)
- [x] 1.2 Refactor `InventorySummaryReportQueryService` to consume the extracted support with no change to its public method signature or output
- [x] 1.3 Run the existing `InventorySummaryReportQueryServiceTest` and confirm it still passes (no behavior regression from extraction)

## 2. Filter value object

- [x] 2.1 Create `App\Services\Reports\InventoryValuationReportFilterData` with `tanggalAwal`, `tanggalAkhir` (inclusive end-of-day), `categoryIds`, `categoryMatchMode` (all/any, default any), `productIds`
- [x] 2.2 Add `fromRequest(Request)` and `fromArray(array)` constructors with sane defaults (default range = current month) mirroring the Inventory Summary filter data
- [x] 2.3 Write a unit test for filter defaults, end-of-day normalization, and match-mode validation

## 3. Valuation ledger query service

- [x] 3.1 Create `App\Services\Reports\InventoryValuationReportQueryService` using the shared replay support; load products filtered by setting, category (all/any), and product IDs
- [x] 3.2 For each product, replay transactions dated strictly before `tanggalAwal` into running stock/WAC without emitting rows, then emit a "Saldo Awal" opening row with running stock, WAC, and value
- [x] 3.3 Continue the replay for transactions within `[tanggalAwal, tanggalAkhir]`, emitting one ledger row per transaction with Tanggal, Tipe transaksi, No. transaksi, Deskripsi, Mutasi (signed), running Stok, Unit, running Harga rata-rata, Harga beli/jual, and running Nilai
- [x] 3.4 Add a transaction-type → Indonesian label map (BUY→Pembelian, SELL/DISPATCH→Penjualan, ADJ→Penyesuaian, TRF→Transfer, opening→Saldo Awal) in one place
- [x] 3.5 Compute per-product subtotal (final running stock + unit, final running value) and a grand total summed across all products before pagination
- [x] 3.6 Group-paginate by product (keep each product's opening + ledger + subtotal rows together) and return a paginator plus the full unpaginated grouped collection and grand total
- [x] 3.7 Sort products by name (default) with a stable tiebreaker

## 4. Service tests

- [x] 4.1 Test opening balance reflects pre-range activity (stock, WAC, value) and shows empty/"-" Mutasi
- [x] 4.2 Test opening balance with no prior activity (zero stock/value, fallback average cost)
- [x] 4.3 Test a multi-day range with a purchase then a sale: assert running stock, recomputed WAC on purchase, unchanged WAC on sale, and running value at each row
- [x] 4.4 Test category all/any match modes and product-id narrowing
- [x] 4.5 Test per-product subtotal and grand-total-across-all-pages correctness under pagination
- [x] 4.6 Test setting scoping (other settings' products/transactions excluded)

## 5. Livewire component

- [x] 5.1 Create `App\Livewire\Reports\InventoryValuationReport` cloning the Inventory Summary filter scaffolding (category multi-select with search + all/any mode, product multi-select with search, period presets, pagination, export trigger)
- [x] 5.2 Replace single `asOfDate` with `tanggalAwal`/`tanggalAkhir`; wire period presets (Hari ini, Pekan ini, Bulan ini, Kuartal ini, Tahun ini, Kemarin, Pekan lalu, Bulan lalu, Kuartal lalu, Tahun lalu, Custom) to populate the range; drop `stockStatus`
- [x] 5.3 Render via the query service and pass grouped rows + subtotals + grand total to the view

## 6. Module wiring (Modules/Reports)

- [x] 6.1 Add `InventoryValuationReportController` with an `index` action gated by `inventoryValuationReports.access`
- [x] 6.2 Register route `reports.inventory-valuation-report.index` in `Modules/Reports/Routes/web.php`
- [x] 6.3 Create the blade view rendering the grouped table: title "Nilai Persediaan Barang" with "(dalam IDR)" note, the 10 columns, per-product group header, opening + ledger rows, per-product subtotal ("Total stok di gudang" + "Subtotal nilai"), and grand "Total nilai"

## 7. Export

- [x] 7.1 Create `App\Exports\InventoryValuationReportExport` emitting columns in reference order (Kode Barang, Barang, Tanggal, Tipe Transaksi, No. Transaksi, Deskripsi, Mutasi, Stok di Tangan, Unit, Harga Rata-Rata, Harga Beli/Jual, Nilai) plus per-product subtotal rows and a grand-total row, pulling the full unpaginated result and honoring active filters
- [x] 7.2 Wire CSV and XLSX export download from the Livewire component
- [x] 7.3 Test export includes all pages, honors filters, and contains subtotals + grand total

## 8. Reports landing navigation

- [x] 8.1 Add the "Nilai persediaan barang" card to the Produk tab on the reports landing page, gated by `inventoryValuationReports.access`, linking to `reports.inventory-valuation-report.index`, with icon, description, and "Lihat laporan" CTA
- [x] 8.2 Update/extend the reports landing feature test to assert the new card renders for permitted users and is hidden otherwise

## 9. Feature test and verification

- [x] 9.1 Add a `Modules/Reports` feature test: permitted user loads the report (200, correct title), unpermitted user is denied
- [x] 9.2 Run the focused suite (`php artisan test` filtered to the new + landing + summary tests) and confirm green
- [x] 9.3 Manually verify the rendered report against the reference sample column order and grouping
