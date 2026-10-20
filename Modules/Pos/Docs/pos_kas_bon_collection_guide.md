# Panduan Penggunaan & Teknis: Pembayaran Kas Bon di POS (POS Kas Bon Collection)

Dokumen ini menjelaskan alur operasional kasir, konfigurasi izin (permissions), pencatatan sesi kasir, perilaku struk, dan batasan pembatalan pembayaran (invalidation boundary) untuk fitur pembayaran Kas Bon pada modul POS.

---

## 1. Konfigurasi Hak Akses & Izin (Permission Setup)

Fitur pembayaran Kas Bon dilindungi oleh izin khusus agar otorisasi di konter kasir dapat dikelola secara independen:

- **Izin Utama:** `pos.kasBon.collect`
  - Memberikan hak kepada kasir untuk membuka modal "Bayar Kas Bon", mencari transaksi Kas Bon yang masih berstatus hutang, meninjau rincian tagihan terkini, dan memproses pembayaran.
  - Sudah didaftarkan ke dalam matriks izin POS (`PosPermissionMatrix`) dan dipaketkan ke dalam role **Kasir** dan **Supervisor/Manager**.
- **Izin Cetak Ulang Struk:** `pos.receipts.reprint`
  - Diperlukan untuk mengakses rute cetak ulang struk koleksi (`pos.kas-bon.collections.receipt.reprint`).
- **Kemandirian dari Pembayaran Global:**
  - Pembayaran Kas Bon di POS **tidak** memerlukan izin pembayaran global (`posPayments.global.access` / `posPayments.global.create`). Ini memungkinkan kasir memproses pelunasan hutang di konter kasir tanpa diberi akses ke modul pembayaran lintas-bisnis tingkat back-office.

---

## 2. Persyaratan Sesi Kasir (Receiving Session Requirements)

- **Sesi Wajib Berstatus BUKA (OPEN):**
  - Pembayaran Kas Bon hanya dapat diproses apabila kasir yang login memiliki sesi POS aktif (`PosSession`) yang berstatus `OPEN` pada cabang/bisnis aktif (`setting_id`).
  - Sesi milik kasir lain atau sesi yang sudah ditutup (`CLOSED`/`FINALIZED`) akan ditolak oleh sistem (`PosKasBonReceivingSessionResolver`).
- **Dukungan Sesi Tanpa Terminal (Terminal-less Sessions):**
  - Kasir dengan sesi terbuka tanpa terminal fisik tetap didukung penuh untuk memproses pelunasan Kas Bon. Dana dicatat dan diatribusikan ke sesi kasir tersebut.

---

## 3. Langkah-Langkah Operasional Kasir (Cashier Steps)

1. **Membuka Modal Pembayaran Kas Bon:**
   - Pada layar Kasir POS (`/pos/sell`), klik tombol **"Bayar Kas Bon"** yang berada di bilah navigasi atas (navigation strip) atau melalui menu dropdown.
   - **Isolasi Keranjang:** Membuka, mencari, atau menutup modal Kas Bon tidak akan mengganggu atau menghapus item belanjaan yang sedang ada di keranjang kasir (*in-progress cart is preserved*).
2. **Pencarian Transaksi Kas Bon:**
   - Sistem menampilkan daftar transaksi Kas Bon yang memiliki sisa hutang aktif (`live_due > 0`) di cabang aktif.
   - Gunakan kolom pencarian untuk menyaring berdasarkan:
     - Nomor transaksi POS (contoh: `TRX-...`)
     - Nama pelanggan
     - Catatan transaksi
     - Nilai nominal (contoh: `150000` atau `150.000`)
   - Klik tombol **"Pilih"** pada baris transaksi yang ingin dibayar.
3. **Peninjauan Tagihan Terkini (Current Bill Review):**
   - Kasir dapat meninjau rincian tagihan otoritatif terkini:
     - Identitas transaksi, nama pelanggan, dan nomor struk asli.
     - Rincian item produk, kuantitas, harga satuan terkini, dan diskon.
     - Ringkasan keuangan: Total Tagihan Terkini, Total Sudah Dibayar Efektif, dan Sisa Hutang (*Live Due*).
   - Apabila terdapat perubahan harga atau pembayaran lain sebelum kasir memproses, sistem mendeteksi ketidaksesuaian *fingerprint* dan menampilkan tombol **"Segarkan Tagihan"**.
4. **Pengisian Form Pembayaran:**
   - **Total Pembayaran:** Dihitung secara otomatis dari penjumlahan nominal **Jumlah** pada masing-masing baris metode pembayaran yang dimasukkan. Kasir juga dapat memantau **Sisa Hutang Setelah Pembayaran** secara real-time.
   - **Pilihan & Pengelolaan Metode:**
     - Kasir dapat menambahkan beberapa baris metode pembayaran sekaligus (misalnya sebagian tunai dan sisanya transfer).
     - Menambah atau menghapus baris metode pembayaran akan mempertahankan seluruh nominal, keterangan, nomor referensi, dan bukti yang sudah dimasukkan pada baris lain.
     - **PENTING:** Metode Kas Bon / Piutang **tidak** tersedia dalam daftar metode pelunasan hutang.
   - **Kolom Keterangan & Nomor Referensi:**
     - **Keterangan:** Teks bebas opsional untuk semua metode (termasuk Tunai / Cash, misalnya "Cicilan kedua") tanpa format angka atau kalkulasi kembalian.
     - **Nomor Referensi (Transfer / Non-Tunai):** Input teks terpisah khusus nomor referensi transaksi bank/EDC, wajib diisi jika aturan metode pembayaran mengharuskannya.
     - Keterangan dan Nomor Referensi disimpan secara independen tanpa saling menimpa.
     - **Unggah Bukti:** Tersedia tombol unggah bukti transfer/foto untuk metode non-tunai.
   - **Tanpa Uang Diterima & Kembalian (Zero Change):**
     - Alur pelunasan Kas Bon tidak menggunakan input Uang Tunai Diterima maupun Kembalian Tunai.
     - Nominal **Jumlah** tunai yang diisi langsung diperlakukan sebagai uang kas yang diterima dan diaplikasikan ke pelunasan hutang (kembalian Rp0).
5. **Eksekusi Pembayaran:**
   - Tombol **"Proses Pembayaran Kas Bon"** aktif ketika total pembayaran valid (lebih dari 0 dan tidak melebihi sisa hutang) serta referensi wajib telah terisi.
   - Klik tombol **"Proses Pembayaran Kas Bon"** untuk memproses alokasi pembayaran secara atomik dalam satu transaksi database.
6. **Struk & Penyelesaian:**
   - Layar menampilkan konfirmasi berhasil dengan Nomor Referensi Koleksi, Jumlah Dibayar, dan Sisa Hutang.
   - Klik **"Cetak Struk"** untuk mencetak struk thermal 72mm.
   - Klik **"Selesai & Tutup"** untuk menutup modal.

---

## 4. Perilaku Struk Koleksi & Cetak Ulang (Receipt & Reprint Behavior)

- **Snapshot Data Imutabel:**
  - Setiap pembayaran Kas Bon menyimpan data struk beku (`receipt_snapshot`) pada tabel `pos_kas_bon_collections`.
  - Informasi pada struk mencakup: No. Koleksi, Ref. Transaksi POS Asli, No. Struk Asli, Nama Pelanggan, Kasir Penerima, Sesi/Terminal, Tanggal & Jam, Rincian Metode & Nominal, Tender, Kembalian (Rp0), dan Sisa Hutang setelah pembayaran tersebut.
- **Stabilitas Data Terhadap Perubahan di Masa Depan:**
  - Jika di kemudian hari sisa hutang transaksi tersebut dilunasi oleh pembayaran lain, atau harga barang diubah di modul Sales, pencetakan ulang struk koleksi lama **tetap menampilkan fakta historis yang sama** (tidak berubah mengikuti pelunasan berikutnya).
- **Audit Cetak Struk:**
  - Setiap kali struk dicetak atau dicetak ulang, aksi tersebut dicatat pada tabel `pos_receipt_print_logs` dengan tipe `PRINT` atau `REPRINT` beserta ID kasir dan timestamp.
  - Struk cetak ulang menampilkan tanda air / badge **"SALINAN / REPRINT STRUK"** dan riwayat cetak ke berapa.

---

## 5. Akuntansi Kas Sesi & Rekonsiliasi (Session Cash & Reconciliation)

- **Pencatatan Uang Laci Fisik:**
  - Pembayaran tunai menghasilkan event kas masuk: `PosSessionCashEvent::EVENT_KAS_BON_COLLECTION_IN` (Direction: `IN`) sebesar nominal kas tunai yang diaplikasikan.
  - Alur pelunasan tidak menghasilkan event kembalian kas keluar (`EVENT_CHANGE_OUT`).
  - Netto kas masuk bertambah sebesar nominal pembayaran tunai yang dimasukkan.
  - Pembayaran non-tunai (Transfer/EDC) dicatat pada rincian metode koleksi tetapi **tidak** menambah saldo fisik laci kasir.
- **Dampak pada Fitur Kasir Terkait:**
  - **Ekspektasi Kas Laci:** Bertambah secara otomatis dan real-time sesuai nominal tunai yang diterima.
  - **Safe Drop (Pengambilan Kas):** Mengonsumsi saldo ekspektasi kas inklusif koleksi Kas Bon.
  - **Peringatan Threshold Laci:** Terpicu jika kas masuk dari pelunasan Kas Bon menyebabkan saldo laci melampaui batas ambang kas.
  - **Penutupan & Rekonsiliasi Sesi:** Ringkasan sesi (`PosSessionSummaryService`) dan audit rekonsiliasi supervisor (`PosReconciliationService`) menampilkan total penerimaan Kas Bon secara terpisah dari total penjualan baru (*Sales Total*), sehingga tidak menimbulkan selisih fiktif pada rekonsiliasi checkout penjualan.

---

## 6. Batasan Pembatalan Pembayaran di Modul Sales (Sales Invalidation Boundary)

- **Pembatalan di Modul Sales:**
  - Jika supervisor membatalkan (*invalidate*) baris `SalePayment` yang dihasilkan dari pelunasan Kas Bon melalui modul Sales, status pembayaran penjualan tersebut akan berubah menjadi `INVALIDATED` dan sisa hutang pelanggan (*live due*) akan **terbuka kembali** secara otomatis.
- **Integritas Catatan Kas POS:**
  - Pembatalan di modul Sales **TIDAK** secara otomatis membalikkan (*silent reversal*) uang kas fisik di laci kasir POS.
  - Catatan `PosSessionCashEvent` dan bukti struk koleksi di POS tetap utuh sebagai riwayat fisik yang sah pada sesi kasir tersebut.
  - Koreksi pengeluaran uang fisik akibat pengembalian dana harus dilakukan melalui alur otorisasi koreksi kas / pengeluaran kas tersendiri oleh supervisor demi menjaga akuntabilitas laci kasir.
