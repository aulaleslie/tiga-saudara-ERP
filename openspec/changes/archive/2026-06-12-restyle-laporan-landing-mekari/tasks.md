## 1. Controller config

- [x] 1.1 In `Modules/Reports/Http/Controllers/ReportsController.php`, rewrite the `$config` array to the Mekari taxonomy and order: `sekilas-bisnis` (Sekilas bisnis), `penjualan`, `pembelian`, `produk`, `aset`, `bank`, `pajak`, `produksi`, then `lainnya` — preserving each existing card's `route` and `permission` exactly, only changing which tab they sit under per the design mapping table
- [x] 1.2 Add a per-tab `icon` (Bootstrap `bi bi-*`) for each Mekari tab
- [x] 1.3 Declare the empty Mekari tabs (`aset`, `bank`, `pajak`, `produksi`) in-order with an empty `cards` array so the existing zero-card filter hides them but reserves their position
- [x] 1.4 Add a `description` string to every card using the drafted Indonesian copy in design.md
- [x] 1.5 Confirm no changes to permission gating, `Route::has` filtering, zero-card tab dropping, or `?tab=` active-tab resolution (reused as-is)

## 2. Landing view — tabs

- [x] 2.1 In `Modules/Reports/Resources/views/index.blade.php`, replace the `nav nav-pills` markup with underline-style tab navigation (links carrying `?tab=<slug>`, active tab marked by a bottom border, inactive tabs muted)
- [x] 2.2 Add scoped `page_css` for the underline tab style (active bottom border, hover, muted inactive)

## 3. Landing view — cards

- [x] 3.1 Restyle each card to the Mekari layout: small leading `bi` icon next to the title, a description paragraph (left-aligned), and a "Lihat laporan" affordance anchored at the bottom
- [x] 3.2 Keep the whole card a single navigable `<a>` to `route($card['route'])`; render the "Lihat laporan" button as a button-styled `<span>` inside the anchor (no nested anchor/button) so the HTML stays valid
- [x] 3.3 Update/replace the existing card `page_css` (hover-shadow etc.) to suit the new left-aligned title+description+button layout

## 4. Tests

- [x] 4.1 Update `Modules/Reports/Tests/Feature/ReportsLandingTest.php` assertions that match old tab labels (Laba/Rugi, Stock, Lainnya) to the Mekari labels (Sekilas bisnis, Produk, Lainnya), keeping all-permission, restricted-user, denied-user, and tab-resolution coverage
- [x] 4.2 Assert all-permission user sees tabs in Mekari order (Sekilas bisnis, Penjualan, Pembelian, Produk, Lainnya) and that Aset/Bank/Pajak/Produksi are not rendered (no mapped cards)
- [x] 4.3 Assert a permitted card renders its description sentence and a "Lihat laporan" call-to-action
- [x] 4.4 Assert the restricted (`saleReports.access`) user sees only the Penjualan tab with Daftar Penjualan + Penjualan Per Customer (no Global, no other tabs)

## 5. Verification

- [x] 5.1 Run focused report tests (`php artisan test` with a `ReportsLanding` filter) and confirm green
- [x] 5.2 Manually verify the landing renders underline tabs and Mekari-style cards, and that each card (body and button) navigates to its existing report unchanged
