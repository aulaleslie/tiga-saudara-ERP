## 1. Report Menu Metadata

- [x] 1.1 Update `ReportsController@index` card configuration to keep all existing implemented cards unchanged.
- [x] 1.2 Append bridge-derived placeholder cards for Sekilas bisnis, excluding Jurnal, Perubahan Modal, and Ringkasan Bisnis.
- [x] 1.3 Append bridge-derived placeholder cards for Penjualan, Pembelian, Produk, and Pajak from `report-sample/bridge`.
- [x] 1.4 Keep Bank, Aset, and Produksi without cards so they remain hidden.
- [x] 1.5 Add explicit placeholder metadata for unimplemented cards, including permission, description, icon, and `Belum tersedia` status.

## 2. Filtering and Rendering

- [x] 2.1 Update report-card filtering so implemented cards still require permission plus `Route::has()`.
- [x] 2.2 Update report-card filtering so placeholder cards require permission but do not require a route.
- [x] 2.3 Update `reports::index` Blade rendering to keep implemented cards clickable exactly as before.
- [x] 2.4 Render placeholder cards as disabled, non-anchor cards with title, description, and `Belum tersedia` status.
- [x] 2.5 Ensure placeholder cards do not show an enabled `Lihat laporan` action or link to any report route.

## 3. Feature Tests

- [x] 3.1 Update all-permission landing test to assert visible tabs are Sekilas bisnis, Penjualan, Pembelian, Produk, Pajak, and Lainnya in order.
- [x] 3.2 Add assertions that Aset, Bank, and Produksi are not shown.
- [x] 3.3 Add assertions that existing implemented cards remain visible and linked to their current routes.
- [x] 3.4 Add assertions that Sekilas bisnis placeholders are visible while Jurnal, Perubahan Modal, and Ringkasan Bisnis are absent.
- [x] 3.5 Add assertions for Penjualan, Pembelian, Produk, and Pajak placeholder visibility.
- [x] 3.6 Add restricted-permission assertions proving placeholder tabs/cards follow the mapped permission family.
- [x] 3.7 Add assertions that placeholder cards show `Belum tersedia` and do not render links to missing report routes.

## 4. Verification

- [x] 4.1 Run the focused Reports landing feature test.
- [x] 4.2 If focused tests pass, run a broader report-related test filter or `php artisan test` as appropriate for the change size.
- [x] 4.3 Manually inspect the resulting `/reports` markup or rendered page to confirm disabled cards are visually distinct and existing cards remain clickable.
