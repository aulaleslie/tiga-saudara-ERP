# Domain Audit: ProductSerialNumber Call Sites

Audit per Task 1.1 mengklasifikasikan setiap call site query ketersediaan serial dan pemutasi status/kondisi ke dalam tiga kategori:
- **Operational Selector**: Memilih serial untuk operasi inventaris (penjualan, transfer keluar, pemakaian). Wajib membatasi pada serial yang siap pakai / sesuai mode.
- **Mutation Boundary**: Memutasi status/lokasi/kondisi serial. Wajib menjaga invariants, validasi ulang kondisi serial sebelum mutasi persisten, dan menjaga rollback atomik.
- **Audit / History Lookup**: Membaca rekam jejak, kartu stok, riwayat perpindahan, atau bukti audit. Tetap menampilkan serial yang telah terjual, rusak, atau hilang tanpa menyamarkan bukti audit.

---

| Modul / Komponen | File / Baris | Peran / Klasifikasi | Deskripsi & Penyesuaian |
|---|---|---|---|
| **Product Model** | `Modules/Product/Entities/ProductSerialNumber.php` | Model Scopes & Presenter | Mendefinisikan `scopeAvailable()`, `scopeSellable()`, `scopeAvailableBroken()`, `isSellable()`, `isAvailableBroken()`, dan `getCombinedState()`. Read-compatibility untuk legacy `status=BROKEN`. |
| **Product Detail** | `app/Livewire/Product/ProductSerialNumbersTable.php` | Operational & Audit UI | Menampilkan 5 tab terpisah: Siap Jual (`sellable`), Rusak (`availableBroken`), Dalam Proses Retur (`returning`), Hilang (`missing`), dan Riwayat/Tidak Tersedia (`history`). Menampilkan label status gabungan Bahasa Indonesia dan catatan provenans `Lokasi Terakhir` untuk unit hilang. |
| **Product Detail View** | `resources/views/livewire/product/product-serial-numbers-table.blade.php` | UI Presenter | Menggunakan `$combinedState` badge dan label; menampilkan lokasi terakhir untuk serial hilang. |
| **Laporan Stok Lintas Bisnis** | `app/Services/Reports/CrossBusinessStockInventoryQueryService.php` | Operational Dialog Query | Dialog Good menggunakan `sellable()`; dialog Bad menggunakan `availableBroken()`. |
| **Laporan Stok Lintas Bisnis UI** | `app/Livewire/Reports/CrossBusinessStockInventory.php` | Reporting & Discrepancy Signal | Menampilkan alert diskrepansi non-mutasi jika bucket count tidak cocok dengan scoped serial count. |
| **Serial Autocomplete** | `app/Livewire/AutoComplete/SerialNumberLoader.php` | Operational Selector | Menggunakan `available()` sebagai dasar, `sellable()` untuk mode normal, dan `availableBroken()` untuk mode rusak. Mengecualikan serial hilang (`MISSING`). |
| **Transfer Scan** | `Modules/Adjustment/Services/TransferScanResolverService.php` | Operational Selector | Memvalidasi mode transfer (`$isBrokenMode`). Menolak serial rusak/hilang pada mode normal, dan menolak serial siap jual/hilang pada mode rusak dengan respon `serial_rejected` berbahasa Indonesia. |
| **Transfer UI** | `app/Livewire/Transfer/SearchProduct.php` | Operational Selector UI | Meneruskan `$this->is_broken_mode` ke scan resolver dan mendispatch event `scanFailed` dengan pesan Bahasa Indonesia yang informatif saat serial ditolak. |
| **Transfer Draft Validation** | `Modules/Adjustment/Services/TransferDraftService.php` | Mutation Boundary | Memvalidasi mode kompatibilitas dengan `isSellable()` dan `isAvailableBroken()`. Menolak serial hilang, terdispatc, atau salah mode; menghitung alokasi bucket rusak dari status canonical `isAvailableBroken()`. |
| **Transfer Movement & Dispatch** | `Modules/Adjustment/Services/TransferMovementService.php` | Mutation Boundary | Pada `allocateSerialized()`, memvalidasi ulang `isSellable()` dan `isAvailableBroken()`. Menentukan bucket `isBroken` menggunakan `isAvailableBroken()` sehingga serial legacy BROKEN diperlakukan secara benar. |
| **POS Scan Resolver** | `Modules/Pos/Services/PosScanResolverService.php` | Operational Selector | Query pencarian serial instan dibatasi pada `sellable()`. Serial rusak, hilang, terjual, dan terkirim tidak dapat ditemukan. |
| **POS Cart Discovery & Append** | `Modules/Pos/Services/PosCartService.php` | Operational Selector | `availableSerialsForProduct()`, `appendSerialByLookupWithinLock()`, dan `assignSerialsWithinLock()` memvalidasi `isSellable()` untuk baris tunggal maupun komponen paket (bundle). |
| **POS Checkout Preflight** | `Modules/Pos/Services/FinalizePosCheckoutService.php` | Mutation Boundary | `validateCartFulfillability()` memvalidasi keberadaan dan `isSellable()` untuk serial biasa maupun serial komponen paket sebelum checkout ledger dibuat. |
| **POS Checkout Allocation** | `Modules/Pos/Services/ResolvePosStockAllocationsService.php` | Operational Selector | `resolve()` memastikan alokasi serial hanya bersumber dari record yang `isSellable()`. |
| **POS Atomic Posting** | `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php` | Mutation Boundary | `assertSerialCurrentlyPostable()` melakukan lock revalidation dan membatalkan mutasi secara atomik jika ada serial yang menjadi rusak atau hilang setelah ditambahkan ke keranjang. |
| **Non-POS Direct Sale** | `Modules/Sale/Http/Controllers/SaleController.php` | Operational Selector & Mutation Boundary | Validasi serial pada baris 716 memverifikasi `$snRecord->isSellable()`. Mencegah penjualan serial berstatus rusak atau hilang pada formulir penjualan manual/non-POS. |
| **Stock Serial Conversion** | `Modules/Product/Services/SerialConversionExecutionService.php` | Mutation Boundary | Mengubah kondisi serial menjadi rusak dengan format kanonikal: `status = ACTIVE` dan `is_broken = true`. |
| **Purchases Return Settlement** | `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php` | Mutation Boundary | Menyelaraskan penulisan kondisi rusak dengan kanonikal `status = ACTIVE` dan `is_broken = true`. |
