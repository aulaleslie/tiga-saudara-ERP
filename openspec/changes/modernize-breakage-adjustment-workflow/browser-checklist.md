# Checklist Verifikasi Manual Browser — Modernisasi Alur Penyesuaian Barang Rusak

**Status eksekusi: BELUM DIJALANKAN (pending).** Semua item di bawah ini
memerlukan verifikasi manusia langsung di browser. Bagian ini hanya dianggap
selesai setelah seseorang benar-benar menjalankan setiap skenario dan mencatat
hasilnya (lulus/gagal + tangkapan layar/catatan) pada bagian "Hasil Eksekusi"
di akhir dokumen ini.

## Persiapan

- Siapkan dua akun pengguna pada pengaturan (setting) aktif yang sama:
  - **Pembuat/Pengubah**: memiliki `adjustments.breakage.create`,
    `adjustments.breakage.edit`, `adjustments.show`.
  - **Penyetuju**: memiliki `adjustments.breakage.approval` (atau
    `adjustments.approval`), `adjustments.show`, dan
    `adjustments.view-system-stock`.
  - Opsional: akun **Peninjau tanpa `adjustments.view-system-stock`**, untuk
    memverifikasi bahwa data stok tersembunyi dengan benar.
- Siapkan minimal dua lokasi standar (bukan konsinyasi) pada pengaturan aktif:
  satu di bawah pengaturan PKP dan (jika memungkinkan, dengan pengaturan lain)
  satu Non-PKP, untuk menguji alokasi kelompok pajak otomatis.
- Siapkan minimal satu produk non-serial dan satu produk serial (dengan
  minimal satu nomor seri berstatus bagus/tersedia terdaftar di lokasi
  tujuan, dan satu nomor seri lain terdaftar di lokasi yang berbeda) pada
  lokasi yang sama.
- Siapkan barcode konversi (barcode satuan turunan) untuk salah satu produk
  non-serial, untuk menguji pemindaian faktor konversi.

## A. Buat Barang Rusak (create-breakage)

1. [ ] Buka halaman "Buat Penyesuaian Barang Rusak": lokasi menggunakan
   dropdown pencarian (bukan autocomplete lama), dan tidak ada komponen
   pencarian produk terpisah di atas tabel (pencarian produk kini ada di
   dalam tabel barang rusak sendiri).
2. [ ] Sebelum memilih lokasi, coba pindai/ketik barcode pada kolom pindai:
   muncul pesan "Pilih lokasi terlebih dahulu sebelum memindai." dan kolom
   pindai tetap terfokus/terpilih untuk diperbaiki.
3. [ ] Pilih lokasi. Pindai barcode utama produk non-serial: baris baru
   ditambahkan dengan kuantitas 1; memindai barcode yang sama lagi menambah
   kuantitas +1 (bukan baris baru).
4. [ ] Pindai barcode konversi produk yang sama: kuantitas bertambah sesuai
   faktor konversi (mis. barcode dus x12 menambah 12), bukan +1.
5. [ ] Pindai barcode/nomor seri yang cocok dengan lebih dari satu
   interpretasi (mis. barcode produk lain yang sama persis dengan suatu
   nomor seri): muncul dialog pilihan ambigu berisi semua kandidat; memilih
   salah satu menerapkannya dengan benar dan menutup dialog.
6. [ ] Pindai kode yang sama sekali tidak dikenal: muncul pesan "...tidak
   ditemukan." dan kolom pindai tetap terpilih untuk koreksi (fokus tidak
   berpindah ke tempat lain).
7. [ ] Coba pindai/tambah kuantitas melebihi stok baik yang tersedia di
   lokasi terpilih: permintaan ditolak dengan pesan "tidak mencukupi" dan
   kuantitas tidak berubah.
8. [ ] Klik "Cari Produk": modal pencarian tampil, hasil pencarian muncul
   saat mengetik, memilih produk menambah baris baru dan menutup modal;
   memilih produk yang sudah ada di daftar TIDAK menambah baris kedua dan
   menampilkan pesan "...sudah ada di daftar (baris N)."
9. [ ] Untuk produk berseri: memindai/mengetik nomor seri yang BUKAN barang
   bagus di lokasi terpilih (mis. di lokasi lain, sudah terkirim, sudah
   rusak, sudah retur, atau tidak aktif) ditolak dengan pesan alasan yang
   jelas dan kuantitas baris tidak bertambah.
10. [ ] Untuk produk berseri: memindai/mengetik nomor seri bagus yang valid
    di lokasi terpilih berhasil ditambahkan; kuantitas baris (hanya-baca)
    otomatis mengikuti jumlah nomor seri terpilih.
11. [ ] Mengubah lokasi setelah ada baris produk memicu dialog konfirmasi
    yang menjelaskan bahwa daftar akan dihapus; membatalkan mengembalikan
    dropdown ke lokasi semula dan mempertahankan semua baris; mengonfirmasi
    mengosongkan seluruh daftar dan berpindah ke lokasi baru.
12. [ ] Kirim dokumen tanpa kuantitas apa pun (atau kuantitas 0 pada semua
    baris): ditolak dengan pesan validasi yang jelas; tidak ada dokumen baru
    tercipta.
13. [ ] Kirim dokumen valid: berhasil, diarahkan ke daftar penyesuaian, dan
    dokumen baru muncul berstatus menunggu persetujuan.

## B. Ubah Barang Rusak (edit-breakage)

14. [ ] Buka dokumen barang rusak pending untuk diubah: lokasi tampil pada
    dropdown pencarian (bukan teks baku/readonly), baris produk dan nomor
    seri yang sudah ada termuat dengan benar (kuantitas sesuai data
    tersimpan).
15. [ ] Ulangi skenario A3–A11 relevan pada form ubah (scan, cari produk,
    ganti lokasi) dan pastikan perilakunya sama dengan form buat.
16. [ ] Simpan perubahan yang gagal validasi (mis. kuantitas melebihi stok):
    kembali ke form dengan baris dan kuantitas yang sudah dimasukkan tetap
    terisi (tidak hilang), disertai pesan galat yang jelas.
17. [ ] Simpan perubahan valid: berhasil, dan detail dokumen menampilkan
    data terbaru.

## C. Tinjauan Pending (Pratinjau Persetujuan)

18. [ ] Buka detail dokumen barang rusak berstatus menunggu persetujuan
    sebagai Penyetuju: judul "Rincian Barang Rusak", status berlencana
    "Menunggu Persetujuan", dan kartu "Pratinjau Persetujuan" tampil.
19. [ ] Untuk setiap produk: kelompok pajak lokasi, stok saat ini
    (bagus/rusak), permintaan rusak, dan proyeksi (bagus/rusak) tampil
    dengan benar untuk peran ber-`adjustments.view-system-stock`.
20. [ ] Buat kondisi konflik (mis. ubah stok produk di database/melalui
    penyesuaian lain sehingga permintaan melebihi stok baik yang tersisa,
    atau produk pada dokumen dihapus): kartu pratinjau menampilkan pesan
    konflik dalam Bahasa Indonesia, lencana berubah menjadi "Ada Konflik",
    dan tombol "Setuju" nonaktif (disabled). Endpoint approve tetap menolak
    permintaan langsung meski tombol dipaksa aktif dari devtools.
21. [ ] Sebagai peninjau TANPA `adjustments.view-system-stock`: kolom stok
    saat ini dan proyeksi tidak tampil sama sekali (termasuk di View Source),
    namun pergerakan yang diminta dan konflik/nomor seri tetap terlihat.

## D. Setujui dan Tolak

22. [ ] Setujui dokumen pending tanpa konflik: konfirmasi muncul menjelaskan
    efek "stok baik menjadi stok rusak"; setelah disetujui, status berubah
    menjadi Disetujui dan stok lokasi berubah sesuai pratinjau (bagus turun,
    rusak naik pada bucket pajak yang sama; total fisik tidak berubah).
23. [ ] Untuk baris berseri yang disetujui: nomor seri yang dipilih berubah
    menjadi status rusak; lokasi dan klasifikasi pajaknya TIDAK berubah.
24. [ ] Tolak dokumen pending: dokumen berpindah status/hilang dari daftar
    menunggu sesuai perilaku penolakan yang ada; tidak ada mutasi stok.
25. [ ] Coba setujui dokumen yang sudah disetujui (mis. lewat riwayat
    browser/tombol ganda): ditolak dengan aman tanpa mutasi ganda.

## E. Tampilan Disetujui (Bukti Immutable)

26. [ ] Buka detail dokumen yang baru saja disetujui: kartu "Perubahan yang
    Diterapkan (Bagus → Rusak)" tampil dengan nama penyetuju dan waktu
    persetujuan.
27. [ ] Ubah stok produk tersebut lagi setelah persetujuan (mis. melalui
    penyesuaian lain): buka kembali detail dokumen yang sudah disetujui ini
    — nilai sebelum/sesudah yang ditampilkan TIDAK berubah mengikuti stok
    terkini; nilai tetap sesuai kondisi saat persetujuan (bukti immutable).
28. [ ] Untuk baris berseri yang disetujui: daftar transisi "Bagus → Rusak"
    per nomor seri tampil dengan benar dari bukti tersimpan.
29. [ ] Buka dokumen barang rusak lama yang disetujui SEBELUM fitur bukti
    audit ini aktif (jika tersedia di data uji): tampil catatan peringatan
    Bahasa Indonesia yang menjelaskan keterbatasan data lama ("fallback"),
    beserta tabel produk sederhana sebagai pengganti.

## F. Bahasa dan Konsistensi Umum

30. [ ] Semua label, tombol, pesan validasi, pesan konfirmasi, dan teks
    pratinjau/bukti pada seluruh alur di atas menggunakan Bahasa Indonesia
    — tidak ada sisa teks Inggris.
31. [ ] Tombol Ubah/Hapus hanya tampil untuk dokumen pending dan pengguna
    dengan izin yang sesuai; tombol Setuju/Tolak hanya tampil untuk
    dokumen pending dan pengguna dengan izin persetujuan.

## Hasil Eksekusi

_(Diisi oleh penguji manusia: tanggal, akun yang digunakan, hasil per nomor
di atas — lulus/gagal, catatan, tangkapan layar jika relevan.)_
