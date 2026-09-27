# Panduan Verifikasi Manual Browser: Hardening Ketersediaan & Kondisi Nomor Seri

Dokumen ini berisi daftar periksa (checklist) pengujian manual melalui browser (UI/web) untuk memvalidasi pemisahan status siklus hidup serial (`ACTIVE`, `SOLD`, `RETURN_IN_PROCESS`, `RETURNED`, `MISSING`) dan kondisi fisik (`is_broken`).

Semua pengujian di bawah ini berstatus **PENDING** untuk dieksekusi secara manual oleh penguji/pengguna. Jangan menggunakan otomasi browser.

---

## 1. Halaman Detail Produk — Tab Nomor Seri

- [ ] **1.1 Validasi Tab Kategori Nomor Seri**
  - **Langkah**: Masuk ke menu Master Data > Produk > Detail Produk (pilih produk dengan nomor seri aktif).
  - **Hasil yang Diharapkan**: Terdapat 5 tab yang jelas:
    1. *Siap Jual* (menampilkan badge hijau `Tersedia — Siap Jual`).
    2. *Rusak* (menampilkan badge kuning `Tersedia — Rusak`).
    3. *Dalam Proses Retur* (menampilkan badge kuning `Dalam Proses Retur`).
    4. *Hilang* (menampilkan badge merah `Hilang — Tidak Tersedia`).
    5. *Riwayat / Tidak Tersedia* (menampilkan serial terjual, dikembalikan, dsb).
  - **Status**: [ ] Pending

- [ ] **1.2 Verifikasi Jumlah (Count Badge) dan Perpindahan Tab**
  - **Langkah**: Periksa angka pada badge tab (misal Siap Jual: 5, Rusak: 1, Hilang: 3). Klik masing-masing tab.
  - **Hasil yang Diharapkan**:
    - Konten tabel hanya menampilkan serial yang relevan dengan tab yang dipilih.
    - Serial pada tab *Rusak* tidak muncul di tab *Siap Jual*.
    - Serial pada tab *Hilang* tidak muncul di tab *Siap Jual*.
  - **Status**: [ ] Pending

- [ ] **1.3 Verifikasi Catatan Provenans Serial Hilang**
  - **Langkah**: Buka tab *Hilang*. Periksa kolom Lokasi.
  - **Hasil yang Diharapkan**: Lokasi ditampilkan dengan label pembantu: `Lokasi Terakhir: [Nama Lokasi]`, menandakan serial tersebut tidak lagi dihitung sebagai stok fisik aktif di lokasi tersebut.
  - **Status**: [ ] Pending

---

## 2. Laporan Stok Lintas Bisnis (Cross-Business Stock Inventory)

- [ ] **2.1 Dialog Nomor Seri Kondisi Baik (Good)**
  - **Langkah**: Buka menu Laporan > Stok Lintas Bisnis. Klik angka stok Baik (Good) pada salah satu produk bernomor seri.
  - **Hasil yang Diharapkan**: Dialog modal hanya menampilkan nomor seri yang berstatus Siap Jual (`ACTIVE` dan `is_broken = false`). Serial yang rusak atau hilang tidak tercantum dalam dialog ini.
  - **Status**: [ ] Pending

- [ ] **2.2 Dialog Nomor Seri Kondisi Rusak (Bad)**
  - **Langkah**: Klik angka stok Rusak (Bad) pada baris produk yang memiliki unit rusak.
  - **Hasil yang Diharapkan**: Dialog modal hanya menampilkan nomor seri rusak (`is_broken = true`). Nomor seri yang siap jual atau hilang tidak muncul.
  - **Status**: [ ] Pending

- [ ] **2.3 Indikator / Alert Diskrepansi Non-Mutasi**
  - **Langkah**: Buka dialog nomor seri pada baris produk/lokasi yang jumlah stok fisiknya sengaja dibuat berselisih dengan jumlah record serial tersedia.
  - **Hasil yang Diharapkan**: Muncul pesan peringatan/alert diskrepansi tanpa mengubah saldo stok sistem.
  - **Status**: [ ] Pending

---

## 3. Transfer Stok Antar Gudang / Toko

- [ ] **3.1 Pencarian & Pemindaian Serial Mode Normal**
  - **Langkah**: Buat transfer stok baru dengan mode Normal (Bukan Barang Rusak). Ketik/pindai nomor seri pada input serial.
  - **Hasil yang Diharapkan**:
    - Serial siap jual dapat dipilih dan ditambahkan ke daftar transfer.
    - Pemindaian/pemilihan serial dengan kondisi Rusak (`is_broken = true`) atau Hilang (`MISSING`) ditolak dengan pesan peringatan berbahasa Indonesia bahwa nomor seri tidak valid atau tidak siap jual.
  - **Status**: [ ] Pending

- [ ] **3.2 Pencarian & Pemindaian Serial Mode Barang Rusak**
  - **Langkah**: Buat transfer stok baru dengan mengaktifkan mode Barang Rusak.
  - **Hasil yang Diharapkan**:
    - Hanya serial berkondisi rusak (`is_broken = true` dan `ACTIVE`, atau legacy `BROKEN`) yang dapat dipilih.
    - Serial yang siap jual (`is_broken = false`) atau hilang ditolak.
  - **Status**: [ ] Pending

---

## 4. Formulir Penjualan Manual (Non-POS)

- [ ] **4.1 Penolakan Nomor Seri Rusak / Hilang pada Penjualan Langsung**
  - **Langkah**: Buka menu Penjualan > Tambah Penjualan. Pilih produk bernomor seri dan coba tetapkan nomor seri yang berstatus Rusak atau Hilang.
  - **Hasil yang Diharapkan**: Formulir memunculkan pesan validasi error bahwa nomor seri tidak tersedia untuk dijual karena rusak atau hilang.
  - **Status**: [ ] Pending

---

## 5. Kasir POS (Point of Sale)

- [ ] **5.1 Autocomplete / Pencarian Nomor Seri di POS**
  - **Langkah**: Buka antarmuka POS. Tambahkan produk bernomor seri ke keranjang, lalu klik pencarian nomor seri.
  - **Hasil yang Diharapkan**: Daftar pencarian nomor seri hanya memunculkan nomor seri Siap Jual yang berada di lokasi gerai/POS aktif. Serial Rusak (`is_broken = true`), Hilang (`MISSING`), Terjual (`SOLD`), atau Dalam Proses Retur tidak muncul dalam saran pencarian.
  - **Status**: [ ] Pending

- [ ] **5.2 Input Manual / Scan Serial Rusak atau Hilang**
  - **Langkah**: Coba masukkan atau pindai nomor seri yang berstatus Rusak atau Hilang ke baris keranjang POS.
  - **Hasil yang Diharapkan**: Sistem menolak nomor seri tersebut dengan pesan bahwa nomor seri tidak tersedia untuk dijual.
  - **Status**: [ ] Pending

- [ ] **5.3 Penjualan Paket Produk (Bundle Component Serials)**
  - **Langkah**: Tambahkan produk paket/bundle yang komponennya memerlukan nomor seri. Pilih nomor seri untuk komponen paket.
  - **Hasil yang Diharapkan**: Validasi yang sama diterapkan pada komponen paket: hanya serial Siap Jual yang dapat dialokasikan ke komponen.
  - **Status**: [ ] Pending

- [ ] **5.4 Pembayaran & Penyelesaian Transaksi (Checkout Finalize)**
  - **Langkah**: Lengkapi nomor seri siap jual pada keranjang POS, pilih metode pembayaran, dan lakukan pembayaran hingga cetak struk.
  - **Hasil yang Diharapkan**:
    - Transaksi berhasil diselesaikan.
    - Status nomor seri di database berubah menjadi `SOLD` dengan referensi detail pengiriman (`dispatch_detail_id`) yang sesuai.
    - Pada riwayat produk, serial tercatat terjual dan tidak dapat dijual kembali.
  - **Status**: [ ] Pending
