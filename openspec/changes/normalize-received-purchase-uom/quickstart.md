# Quickstart: Normalisasi UOM Penerimaan Pembelian

Fitur ini menyediakan alur kerja khusus untuk mengubah satuan (UOM) dari barang yang sudah diterima pada pesanan pembelian (Purchase). Normalisasi ini berguna jika penerimaan barang sebelumnya keliru menggunakan satuan yang lebih besar (misalnya `BOX`) sedangkan produk seharusnya dikelola dalam satuan dasar (misalnya `PCS`), dan belum terjadi penjualan/pengeluaran barang (dispatched sales/checkouts).

## Alur Kerja Operasional

1.  **Akses**: Buka halaman Detail Pembelian. Jika pembelian sudah berstatus "Diterima" atau "Diterima Sebagian", tombol **"Normalisasi UOM"** akan muncul di sebelah tombol "Koreksi Penerimaan". (Membutuhkan akses `purchases.received.uom-normalize`).
2.  **Pilih Produk**: Pilih produk (yang dikelola stoknya dan tidak menggunakan nomor seri) yang ingin dinormalisasi.
3.  **Pilih Konversi UOM**: Pilih faktor konversi yang benar. Konversi harus mengarah ke satuan dasar produk. (misal: 1 BOX = 12 PCS).
4.  **Pilih Baris**: Centang satu atau beberapa baris pembelian untuk produk yang sama.
5.  **Isi Alasan**: Tuliskan alasan normalisasi untuk keperluan audit.
6.  **Pratinjau**: Sistem akan mengecek kelayakan:
    *   Apakah baris sudah lengkap diterima?
    *   Apakah sudah ada riwayat transaksi pengeluaran (Penjualan yang di-dispatch, POS checkout selesai, transfer keluar, dll) yang memblokir perubahan ini? (Penjualan berstatus *Draft/Pending* tidak memblokir).
    *   Apakah transaksi inventaris dapat ditelusuri dengan valid (Provenance)?
    Jika layak, pratinjau akan menampilkan ringkasan perubahan jumlah (qty), nilai HPP proyeksi, dan tidak mengubah nilai moneter faktur (sub-total tetap sama).
7.  **Eksekusi**: Jalankan normalisasi. Sistem secara atomik akan memperbarui detail pembelian, penerimaan, riwayat transaksi (`BUY`), merekonstruksi jumlah stok, merekalulasi HPP rata-rata produk, dan mencatat riwayat ke tabel audit (`uom_normalization_batches`).

## Batasan Kelayakan (Eligibility Boundaries)

Normalisasi **hanya dapat dilakukan** jika memenuhi seluruh kriteria berikut:
*   Produk dikelola stoknya (`stock_managed = true`).
*   Produk **tidak menggunakan nomor seri**.
*   Konversi yang dipilih merujuk tepat ke satuan dasar (*base unit*) produk saat ini.
*   Seluruh baris penerimaan yang dipilih **telah diterima secara penuh** (kuantitas diterima >= kuantitas dipesan).
*   **Stok rusak (broken quantity) di semua lokasi harus nol** (kebijakan konservatif). Jika ada stok rusak, harus diselesaikan atau dijustifikasi melalui penyesuaian stok manual sebelum normalisasi dapat dilakukan.
*   Belum ada **transaksi pengeluaran** (atau transaksi persediaan lain selain `BUY`) setelah penerimaan tersebut, seperti:
    *   Penjualan yang berstatus *Dispatched, Partially Dispatched*, atau *Delivered*.
    *   Transaksi POS yang berstatus *Completed* (termasuk sebagai komponen *bundle*).
    *   Transaksi Retur (`RET`), Transfer (`TRF`), Penyesuaian (`ADJ`), Pemecahan (`BRK`), Penggantian (`RPL`), atau Impor awal (`IMP`/`INIT`).
*   Setiap baris belum pernah dinormalisasi sebelumnya.
*   **Kepemilikan barcode produk harus dapat dibuktikan** melalui registry `barcode_identities`. Jika produk memiliki barcode tetapi tidak ada entri registry yang sah, atau migrasi barcode akan bertabrakan dengan barcode konversi unit lain, normalisasi diblokir sebelum ada perubahan data apa pun.

## Migrasi Barcode

Jika produk memiliki barcode sendiri (`products.barcode`) yang kepemilikannya terbukti melalui registry `BarcodeIdentity`, barcode tersebut dianggap mewakili satuan dasar **lama** dan akan dipindahkan secara otomatis ke baris konversi unit lama-ke-baru yang baru dibuat, dalam transaksi eksekusi yang sama. Barcode pada konversi unit yang sudah ada (mis. DUS, LUSIN) tidak pernah disentuh oleh proses rebase faktor — hanya `conversion_factor` dan `base_unit_id` yang diperbarui.

## Resolusi Transaksi Warisan (Legacy Transaction Match)

Mulai pembaruan ini, setiap persetujuan penerimaan secara persisten merekam `received_note_detail_id` pada transaksi `BUY` di buku inventori (Provenance Tautan).
Namun, untuk **penerimaan lama (legacy)** sebelum adanya pembaruan ini:
*   Sistem menggunakan *Legacy Transaction Resolver* secara otomatis saat Pratinjau.
*   Pencocokan dilakukan berdasarkan bukti: produk, lokasi, *setting*, jumlah persis, kata kunci referensi PO dalam kolom *reason*, dan waktu persetujuan.
*   Jika kandidat transaksi bersifat **ambigu** (misal dua penerimaan identik pada detik yang sama), normalisasi akan dicegah demi keamanan data.
*   **Remediasi**: Jika tertahan oleh kecocokan ambigu pada penerimaan lama, pengguna harus melakukan *Koreksi Moneter/Nilai* manual (yang menghasilkan jurnal *Adjustment*) ketimbang menggunakan fitur Normalisasi ini, atau menghubungi tim teknis untuk memetakan manual relasi di tabel `transactions`.

## Prosedur Insiden dan Rollback

Pembaruan UOM Normalisasi dieksekusi di dalam lingkup *database transaction* yang memegang penguncian baris eksklusif (`lockForUpdate`).

Jika produk hanya memiliki baris harga di cabang lain, sistem akan menyesuaikan `Harga Pembelian Terakhir` dan `HPP Rata-rata` cabang tersebut ke satuan dasar baru. Harga jual dan harga tingkat tidak diubah. Jika cabang lain memiliki stok, penerimaan/pembelian, atau riwayat transaksi produk, normalisasi ditolak sampai footprint tersebut diremediasi.
1.  **Jika terhenti di tengah jalan / Gagal**: Sistem secara otomatis akan melakukan *Rollback* atas perubahan stok dan transaksi inventaris. Tidak akan ada perubahan parsial.
2.  **Idempotency**: Jika sebuah penerimaan diklik dua kali, keunikan pada tabel `uom_normalization_lines` akan menggagalkan percobaan kedua.
3.  **Kesalahan Konversi**: Jika pengguna menjalankan normalisasi dengan faktor yang salah (UOM yang salah), **tidak ada fitur Undo otomatis**. Karena ini adalah modifikasi sejarah inventori, perbaikan atas kesalahan normalisasi harus dilakukan melalui mekanisme penyesuaian stok manual (Stock Adjustment) atau rekalkulasi HPP manual oleh *Super Admin*. Oleh karena itu, *Pratinjau* wajib dibaca sebelum konfirmasi eksekusi.
