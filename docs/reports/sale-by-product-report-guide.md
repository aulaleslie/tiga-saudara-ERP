# Panduan Laporan Penjualan per Produk

Laporan **Penjualan dengan Produk** (`Penjualan per produk`) menampilkan ringkasan penjualan per produk dalam enam kolom numerik (sebelum pajak / *tax-exclusive*): **Kode Produk**, **Nama Produk**, **Kuantitas Terjual**, **Satuan**, **Total Nilai terjual**, dan **Harga Penjualan Rata-rata**. Laporan ini bersifat *read-only*, tidak menampilkan HPP/modal, peran penjualan, atau label status kelengkapan.

---

## 1. Agregasi Produk & Sumber Data

Setiap baris hasil dikelompokkan secara deterministik berdasarkan **identitas produk** (`product_id`) dan **satuan yang kompatibel** lintas bisnis terpilih:

- **Penjualan langsung (`sale_details`)** dan **komponen paket (`sale_bundle_items`)** dari transaksi yang memenuhi syarat (`DISPATCHED` atau `RETURNED PARTIALLY`, tidak diarsipkan, sesuai tanggal pelaporan efektif dan bisnis terpilih) **digabungkan menjadi satu baris** per produk dan satuan.
- **Harga Penjualan Rata-rata** dihitung setelah penggabungan kuantitas dan nilai penjualan lintas sumber serta lintas bisnis:
  $$\text{Harga Penjualan Rata-rata} = \frac{\text{Total Nilai terjual gabungan}}{\text{Kuantitas Terjual gabungan}}$$
  (bernilai `0` jika kuantitas `0`).

> **Contoh Gabungan Langsung + Komponen:** Jika suatu produk terjual langsung `2` unit senilai `60.000` dan terjual sebagai komponen paket POS `1` unit senilai `25.000`, laporan menampilkan **satu baris** dengan Kuantitas Terjual `3`, Total Nilai terjual `85.000`, dan Harga Penjualan Rata-rata `28.333,33` (`85.000 / 3`).

---

## 2. Atribusi Nilai Penjualan Bundle & Filter Katalog

| Jenis Baris | Perlakuan Nilai Penjualan (sebelum pajak) | Perlakuan Induk (`sale_details`) |
|---|---|---|
| **Penjualan biasa** | Menggunakan `sub_total` (dikurangi pajak jika *tax-included*) | Tidak ada pengurangan |
| **Komponen POS teralokasi** (terhubung `pos_checkout_sales`) | Menggunakan `sub_total` dan `tax_amount` komponen yang tersimpan | Induk menerima nilai residual (`sub_total` induk dikurangi seluruh komponen saudara sebelum filter katalog) |
| **Komponen Non-POS** (penjualan standar) | Nilai komersial tersimpan `0` (tetap angka `0`); `informational_item_price` tidak dijadikan pendapatan | Induk mempertahankan nilai komersial penuh |
| **Komponen Standalone** (`sale_detail_id` kosong) | Menggunakan `sub_total` dan `tax_amount` miliknya sendiri | Tidak ada induk |
| **Induk placeholder split-owner** (induk POS dengan komponen terlampir, qty `0`, residual `0`) | Komponen tetap melapor kuantitas dan nilai miliknya | Tidak memunculkan baris kosong/hantu |

> **Contoh Dekomposisi POS & Filter Kategori ATK:** Jika paket induk **LAPTOP** memiliki `sub_total = 125.000` dan komponen **Mouse (ATK)** memiliki `sub_total = 25.000`, maka induk berkontribusi `100.000` dan komponen Mouse berkontribusi `25.000` (total tetap `125.000`, tanpa penghitungan ganda). Saat pengguna memfilter kategori **ATK** saja, filter diterapkan ke produk yang dilaporkan (termasuk komponen), sehingga Mouse tetap menampilkan kuantitas dan nilai `25.000` karena dekomposisi saudara dihitung sebelum filter katalog.

### Catatan Cakupan Snapshot Data

Audit *read-only* pada snapshot produksi lokal menemukan `1.732` baris komponen terhubung POS pada `549` transaksi penjualan dan tidak menemukan baris komponen non-POS maupun *standalone*. Laporan ini tidak melakukan alokasi ulang historis maupun mutasi transaksi/snapshot; snapshot biaya yang tersimpan tetap utuh untuk konsumen laporan lain.

---

## 3. Filter & Periode

- **Filter Kategori (termasuk subkategori):** Memilih kategori induk (mis. `ATK`) otomatis mencakup seluruh subkategori turunannya tanpa duplikasi.
- **Logika Filter:** Di dalam masing-masing filter Kategori, Merek, dan Produk berlaku logika **OR**; antar filter berlaku logika **AND**.
- **Periode (`Asia/Makassar`):** Mendukung *preset* `Hari Ini`, `Minggu Ini/Lalu` (Senin–Minggu), `Bulan Ini/Lalu`, `Tahun Ini/Lalu`, dan `Rentang khusus` dengan sinkronisasi dua arah tanpa menerapkan filter secara otomatis.

---

## 4. Pemetaan Kolom Layar & Ekspor (Excel / CSV)

Ekspor Excel (`.xlsx`) dan CSV (`.csv`) menggunakan dataset dan total yang sama persis dengan pratinjau layar, dilindungi oleh validasi snapshot filter, dan mempertahankan angka `0` numerik (*strict null comparison*).

| Urutan | Kolom Layar & Ekspor | Format / Catatan |
|---|---|---|
| 1 (`A`) | `Kode Produk` | Kosong jika produk tidak memiliki kode |
| 2 (`B`) | `Nama Produk` | Mengutamakan nama katalog saat ini |
| 3 (`C`) | `Kuantitas Terjual` | Numerik (`#,##0.00`) |
| 4 (`D`) | `Satuan` | Satuan produk |
| 5 (`E`) | `Total Nilai terjual` | Numerik (`#,##0.00`), termasuk `0` valid |
| 6 (`F`) | `Harga Penjualan Rata-rata` | Numerik (`#,##0.00`) |

- **Baris Footer (`Total Keseluruhan`):** Tepat 6 kolom; label di kolom `A` (layar: `colspan="4"`), total `Total Nilai terjual` lintas seluruh halaman di kolom `E`, kolom `F` kosong.
- **Metadata XLSX (baris 1–4, digabung `A:F`):** Nama perusahaan / cakupan bisnis, judul `Penjualan dengan Produk`, rentang tanggal (`Periode: dd/mm/yyyy - dd/mm/yyyy`), dan `(dalam IDR)`.
