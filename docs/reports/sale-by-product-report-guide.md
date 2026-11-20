# Panduan Laporan Penjualan per Produk (Snapshot Modal & Bundle)

Laporan **Penjualan dengan Produk** (`Penjualan per produk`) menampilkan kuantitas terjual, nilai penjualan (sebelum pajak / tax-exclusive), dan **modal historis (HPP)** berdasarkan snapshot biaya yang tersimpan pada transaksi penjualan. Laporan ini bersifat *read-only* dan tidak menghitung laba bersih (*net profit*).

---

## 1. Sumber Data & Peran Penjualan (`Dijual sebagai`)

Setiap baris hasil dikelompokkan secara deterministik berdasarkan **Produk**, **Satuan**, dan **Dijual sebagai**:

- **Penjualan langsung / paket** (`direct`): berasal dari baris `sale_details` pada penjualan yang memenuhi syarat (`DISPATCHED` atau `RETURNED PARTIALLY`, tidak diarsipkan, sesuai tanggal pelaporan efektif dan bisnis terpilih).
- **Komponen paket** (`component`): berasal dari baris `sale_bundle_items` (baik komponen yang terhubung ke paket induk maupun item komersial *standalone*).

Pemisahan peran ini mencegah kuantitas komponen yang tidak memiliki nilai jual tersendiri mengencerkan **Harga Penjualan Rata-rata** penjualan langsung produk yang sama.

### Atribusi Nilai Penjualan Bundle

| Jenis Baris | Perlakuan Nilai Penjualan | Perlakuan Induk (`sale_details`) |
|---|---|---|
| **Penjualan biasa** | Menggunakan `sub_total` (dikurangi pajak jika *tax-included*) | Tidak ada pengurangan |
| **Komponen POS teralokasi** (terhubung `pos_checkout_sales`) | Menggunakan `sub_total` dan `tax_amount` komponen yang tersimpan | Induk menerima nilai residual (`sub_total` induk dikurangi seluruh komponen saudara sebelum filter katalog) |
| **Komponen Non-POS** (penjualan standar) | Ditampilkan sebagai `Included in bundle`; `informational_item_price` tidak dijadikan pendapatan | Induk mempertahankan nilai komersial penuh |
| **Komponen Standalone** (`sale_detail_id` kosong) | Menggunakan `sub_total` dan `tax_amount` miliknya sendiri | Tidak ada induk |
| **Data historis ambigu** | Ditampilkan sebagai `Tidak tersedia` (tidak memakai harga katalog saat ini) | Tidak mengurangi induk secara spekulatif |

> **Contoh Dekomposisi POS:** Jika paket induk memiliki `sub_total = 125.000` dan komponen ATK memiliki `sub_total = 25.000`, maka induk berkontribusi `100.000` dan komponen ATK berkontribusi `25.000` (total tetap `125.000`, tanpa penghitungan ganda). Jika pengguna memfilter kategori **ATK** saja, komponen ATK tetap menampilkan `25.000` karena dekomposisi saudara dihitung sebelum filter katalog.

---

## 2. Modal Historis (HPP) & Kelengkapan Data

- **Total Modal** dihitung dari `cost_unit_snapshot × kuantitas terjual saat ini` (mengikuti kuantitas setelah *settlement* / retur parsial).
- **Modal Rata-rata/unit** = `Total Modal / Kuantitas Terjual` (rata-rata tertimbang kuantitas lintas bisnis).
- Laporan **tidak** membaca harga beli katalog saat ini dan **tidak** membuat snapshot baru.

### Perbedaan Nol Valid vs Modal Belum Lengkap

- **Nol valid (`Rp 0`, Lengkap):** Misalnya produk non-stok (`NON_STOCK_MANAGED`) atau snapshot valid bernilai `0` dengan timestamp dan sumber yang sah. Tetap diekspor sebagai angka `0`.
- **Modal belum lengkap (`MISSING_AVERAGE_PRICE` atau snapshot kosong/tidak valid):** Meskipun tersimpan `0`, baris ditandai **Modal belum lengkap** (atau **Parsial** jika sebagian baris memiliki HPP valid). **Modal Rata-rata/unit** dikosongkan (`—` di layar, kosong di Excel/CSV) agar tidak menghasilkan rata-rata yang menyesatkan.
- Sumber historis valid seperti `BACKFILL_*` dan `HPP_SNAPSHOT_IMPORT` tetap dipertahankan sebagai HPP valid.

---

## 3. Contoh Filter & Rata-rata Tertimbang Lintas Bisnis

- **Filter Kategori (ATK):** Memilih kategori induk `ATK` secara otomatis mencakup seluruh subkategori turunannya (mis. `ATK > Kertas`, `ATK > Pulpen`) tanpa duplikasi.
- **Kombinasi Filter:** Di dalam masing-masing filter Kategori, Merek, dan Produk berlaku logika **OR** (salah satu cocok). Antar filter berlaku logika **AND** (harus memenuhi semua filter yang diisi). Produk tidak wajib dipilih — filter ATK saja sudah dapat dijalankan.
- **Rata-rata Tertimbang Lintas Bisnis:** Jika Bisnis A menjual `2` unit dengan HPP `100` dan Bisnis B menjual `3` unit dengan HPP `200`, maka baris gabungan menampilkan Kuantitas `5`, Total Modal `800`, dan Modal Rata-rata/unit `160`.

---

## 4. Pemetaan Kolom Layar & Ekspor (Excel / CSV)

Ekspor Excel (`.xlsx`) dan CSV (`.csv`) menggunakan dataset dan total yang sama persis dengan pratinjau layar, serta dilindungi oleh validasi snapshot filter (perubahan filter apa pun termasuk Bisnis, Kategori, Merek, atau Produk mengharuskan klik **Filter** ulang sebelum ekspor).

| Kolom Layar | Kolom Ekspor (Excel/CSV) | Catatan |
|---|---|---|
| Kode Produk | `Kode Produk` | Kosong jika produk tidak memiliki kode |
| Nama Produk | `Nama Produk` | Mengutamakan nama katalog saat ini |
| Dijual sebagai | `Dijual sebagai` | `Penjualan langsung / paket` atau `Komponen paket` |
| Kuantitas Terjual | `Kuantitas Terjual` | Numerik (`#,##0.00`) |
| Satuan | `Satuan` | Satuan produk |
| Total Nilai terjual | `Total Nilai terjual` | Numerik; kosong jika `Included in bundle` / `Tidak tersedia` |
| Harga Penjualan Rata-rata | `Harga Penjualan Rata-rata` | Numerik jika lengkap; kosong jika parsial / tidak tersedia |
| *(Indikator status)* | `Status Nilai Terjual` | `Lengkap`, `Parsial (...)`, `Included in bundle`, atau `Tidak tersedia` |
| Total Modal | `Total Modal` | Numerik (termasuk `0` valid); kosong jika seluruh baris belum lengkap |
| Modal Rata-rata/unit | `Modal Rata-rata/unit` | Numerik jika lengkap; kosong jika parsial / belum lengkap |
| *(Indikator status)* | `Status Modal` | `Lengkap`, `Parsial (Modal belum lengkap; ...)`, atau `Modal belum lengkap` |
