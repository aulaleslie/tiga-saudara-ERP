# Panduan Laporan Stok Persediaan Lintas Bisnis

Laporan **Stok Persediaan Lintas Bisnis** menampilkan saldo stok (Bagus, Rusak, dan Dalam Pengiriman) lintas bisnis/cabang yang diizinkan bagi pengguna, beserta opsi tampilan harga produk per bisnis dan ekspor Excel dengan tiga pilihan konten.

---

## 1. Filter, Draft vs Diterapkan, dan Pencarian

### Siklus Draft & Terapkan Filter
- **Keadaan awal:** Saat halaman dibuka, laporan langsung menampilkan data dengan seluruh bisnis yang diizinkan, ketersediaan `Semua Stok`, dan tanpa batasan pencarian/kategori/merek/produk.
- **Tata letak kontrol:**
  - **Baris filter utama:** `Pencarian`, `Bisnis / Cabang`, dan `Ketersediaan Stok` sejajar dengan tinggi konsisten (menumpuk rapi pada layar sempit).
  - **Baris aksi filter:** Tombol **Kategori, Merek & Produk** (dengan lencana jumlah pilihan *draft*) di sisi kiri; kelompok tombol **Reset**, **Batalkan**, dan **Terapkan Filter** di sisi kanan.
  - **Ringkasan filter aktif (`Diterapkan:`):** Tampil tepat di bawah area filter untuk menampilkan kriteria yang benar-benar diterapkan (tanpa mencampur pilihan *draft*), disertai penanda **Perubahan belum diterapkan** saat ada perubahan *draft* yang belum diterapkan.
  - **Toolbar presentasi (tepat di atas tabel):** `Format Kuantitas` dan `Tampilkan Harga` di sisi kiri (berefek langsung tanpa menerapkan *draft* filter) dan tombol **Ekspor** di sisi kanan.
- **Draft vs Diterapkan:** Perubahan pada kotak **Pencarian**, **Bisnis / Cabang**, **Ketersediaan Stok**, serta pilihan **Kategori**, **Merek**, dan **Produk** di laci *Kategori, Merek & Produk* bersifat *draft* sampai pengguna menekan **Terapkan Filter**.
  - Saat masih *draft*, tabel hasil, kolom bisnis, penanda (*marker*) nomor serial, dan ringkasan `Diterapkan:` tetap mempertahankan kriteria terakhir yang diterapkan.
  - Ekspor Excel diblokir sementara apabila terdapat perubahan filter yang belum diterapkan.
- **Batalkan (`Batalkan`):** Mengembalikan seluruh input *draft* ke kriteria terakhir yang diterapkan dan memulihkan kelayakan ekspor.
- **Reset (`Reset filter`):** Mengembalikan kriteria ke nilai bawaan (seluruh bisnis yang diizinkan, `Semua Stok`, pencarian dan seluruh pilihan kosong) lalu langsung menerapkannya pada halaman 1.
- **Validasi bisnis kosong:** Jika pengguna mencoba menerapkan filter tanpa memilih minimal satu bisnis yang diizinkan, sistem menolak permintaan dan meminta pemilihan minimal satu bisnis (tanpa mengganti dengan bisnis lain secara diam-diam).
- **Reset halaman:** Menekan **Terapkan Filter** dari halaman mana pun (mis. halaman 2) otomatis mengatur ulang paginasi ke halaman 1 dalam satu kali pemuatan.

### Pemilih Kategori, Merek, dan Produk
- **Multi-pilihan berbasis kotak centang:** Pencarian kategori, merek, dan produk tetap mempertahankan teks pencarian dan daftar pilihan terbuka selama pengguna mencentang/menghapus beberapa item.
- **Chip di atas input:** Item yang dipilih tampil sebagai *chip* di area gulir terbatas tepat di atas kolom pencarian, lengkap dengan jumlah terpilih, tombol hapus per item, dan tombol **Hapus semua**.
- **Kategori turunan (descendants):** Memilih kategori induk (mis. `ATK`) otomatis mencakup seluruh subkategori turunannya (mis. `ATK > Kertas > HVS`) secara aman terhadap siklus (*cycle-safe*) tanpa menduplikasi baris produk.
- **Logika kombinasi:**
  - **OR** di dalam masing-masing pemilih (mis. 2 merek terpilih -> produk milik salah satu merek).
  - **AND** antar pemilih dan kriteria lain (Kategori AND Merek AND Produk AND Pencarian AND Ketersediaan Stok).
- **Batas tampilan & pemilihan massal:** Pencarian menampilkan hingga 25 hasil pertama dengan pesan petunjuk penyempitan pencarian jika masih ada hasil lain; pemilih Produk mendukung **Pilih semua hasil** dengan batas 500 produk per aksi.

### Pencarian Umum (Nama, Kode, Barcode, & Nomor Serial)
- Pencarian umum mempertahankan pencocokan multi-token tanpa urutan pada nama produk, kode produk, barcode, nama kategori, dan nama merek, serta pencocokan persis (*exact match*) pada barcode dan nomor serial.
- Pencarian nomor serial operasional yang cocok persis akan menandai sel Bagus/Rusak pada bisnis/lokasi terkait (*serial marker*) setelah filter diterapkan.

---

## 2. Harga Produk per Bisnis (Opsional & Terotorisasi)

### Izin Akses
- Akses laporan stok tetap menggunakan izin `inventory.view_remaining_stock`.
- Melihat atau mengekspor harga produk memerlukan **kedua izin**: `inventory.view_remaining_stock` **dan** `products.view_prices`.
- Pengguna tanpa `products.view_prices` tetap dapat menggunakan laporan stok dan ekspor **Stok saja**; kontrol harga disembunyikan dan permintaan langsung ke data/ekspor harga ditolak oleh server (`403`) tanpa membocorkan nilai harga.

### Pemetaan Empat Kolom Harga (`product_prices`)
Setiap grup bisnis menampilkan empat kolom harga **satu kali per bisnis** (tidak pernah diulang per lokasi gudang), berdasarkan baris harga tersimpan saat ini pada tabel `product_prices` untuk pasangan `(product_id, setting_id)`:

| Urutan | Kolom Layar & Excel | Kolom Sumber (`product_prices`) | Catatan |
|---|---|---|---|
| 1 | `Harga Modal` | `average_purchase_price` | Biaya rata-rata tersimpan saat ini per satuan dasar (bukan HPP historis transaksi penjualan) |
| 2 | `Harga Jual` | `sale_price` | Harga jual utama saat ini per satuan dasar |
| 3 | `Tier 1` | `tier_1_price` | Harga tier 1 saat ini per satuan dasar |
| 4 | `Tier 2` | `tier_2_price` | Harga tier 2 saat ini per satuan dasar |

### Aturan Nilai Kosong vs Nol & Satuan Dasar
- **Tanpa fallback:** Jika suatu bisnis tidak memiliki baris `product_prices` atau kolom bernilai `NULL`, sistem **tidak** meminjam harga dari bisnis lain, kolom produk lama (`products.product_price` / `product_cost`), atau snapshot penjualan historis.
- **Tersimpan `0` vs Tidak Tersedia (`NULL`/kosong):**
  - Nilai `0.00` yang tersimpan tampil sebagai `Rp 0` di layar dan diekspor sebagai angka `0` di Excel.
  - Nilai `NULL` atau baris harga yang tidak ada tampil sebagai `—` di layar dan diekspor sebagai sel kosong di Excel.
  - Contoh: Nilai `2500000.00` tampil sebagai `Rp 2.500.000` di layar dan tersimpan sebagai nilai numerik `2500000` berformat `#,##0.00` di Excel.
- **Toggle `Tampilkan Harga`:** Mati (*off*) secara bawaan. Mengaktifkan atau menonaktifkan toggle bersifat presentasi langsung (tidak menerapkan perubahan filter *draft* dan tidak mengulang agregasi stok/pembelian).
- **Satuan dasar (`Satuan`):** Saat harga ditampilkan, kolom `Satuan` muncul setelah `Merek`. Mengubah **Format Kuantitas** (`Hanya Angka` / `Dengan Satuan`) atau membuka rincian lokasi bisnis **tidak** mengubah satuan maupun nominal harga.

---

## 3. Ekspor Excel (`.xlsx`)

Pilihan konten ekspor berdiri sendiri dari status toggle `Tampilkan Harga` di layar dan selalu mengikuti filter yang telah diterapkan serta cakupan bisnis yang diizinkan:

1. **Stok saja** *(bawaan)*: Tersedia bagi seluruh pengguna laporan stok. Menampilkan seluruh lokasi secara terbuka (*fully expanded*), total global, dan kolom *Dalam Pengiriman* per bisnis.
2. **Harga saja**: Memerlukan `products.view_prices`. Dapat diekspor meskipun `Tampilkan Harga` sedang nonaktif di layar. Menampilkan identitas produk (`Produk`, `Kategori`, `Merek`, `Satuan`) dan empat kolom harga per bisnis **tanpa** kolom lokasi maupun kuantitas stok.
   - *Contoh dengan filter ketersediaan:* Jika pengguna menerapkan `Ketersediaan Stok = Tersedia (> 0)` lalu mengekspor **Harga saja** saat harga di layar masih disembunyikan, berkas Excel hanya memuat produk yang memiliki stok > 0 pada bisnis terpilih, beserta empat kolom harga per bisnis tanpa kolom stok/lokasi.
3. **Stok + Harga**: Memerlukan `products.view_prices`. Menampilkan `Satuan`, total global, rincian lokasi per bisnis + *Dalam Pengiriman*, diikuti empat kolom harga **satu kali per bisnis** (tidak diulang untuk setiap lokasi).
