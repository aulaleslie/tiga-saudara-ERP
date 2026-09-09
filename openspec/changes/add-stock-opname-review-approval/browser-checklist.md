# Checklist Verifikasi Manual Browser — Stock Opname Review & Approval

**Status eksekusi: BELUM DIJALANKAN (pending).** Semua item di bawah ini
memerlukan verifikasi manusia langsung di browser. Bagian ini hanya dianggap
selesai setelah seseorang benar-benar menjalankan setiap skenario dan mencatat
hasilnya (lulus/gagal + tangkapan layar/catatan) pada bagian "Hasil Eksekusi"
di akhir dokumen ini. Jangan tandai tugas 6.7 selesai di `tasks.md` tanpa
catatan eksekusi ini.

## Persiapan

- Siapkan dua akun pengguna pada pengaturan (setting) aktif yang sama:
  - **Penghitung (Counter)**: memiliki `adjustments.show`, `adjustments.edit`,
    `adjustments.delete`, TIDAK memiliki `adjustments.view-system-stock`,
    TIDAK memiliki `adjustments.approval`.
  - **Peninjau-Penyetuju (Reviewer-Approver)**: memiliki `adjustments.show`,
    `adjustments.view-system-stock`, DAN `adjustments.approval`.
  - Opsional, disarankan: akun **Peninjau tanpa persetujuan** — memiliki
    `adjustments.view-system-stock` tetapi TIDAK `adjustments.approval`, untuk
    memverifikasi kedua izin tersebut independen.
- Siapkan minimal satu produk non-serial dan satu produk serial (dengan
  minimal satu nomor seri terdaftar di lokasi tujuan dan satu di lokasi lain)
  pada lokasi yang sama, agar setiap status/keadaan di bawah dapat diuji nyata
  (bukan dokumen kosong).
- Siapkan kondisi yang akan memicu peringatan: jumlah hitung fisik yang
  melebihi total stok di semua lokasi terkait, dan/atau stok yang berubah
  (drift) setelah baseline diambil.

## A. Tampilan Bahasa Indonesia (semua peran, semua status)

1. [ ] Judul tab/halaman menampilkan "Rincian Stock Opname" (bukan "Adjustment
   Details") untuk dokumen stock opname versi baru.
2. [ ] Semua label status (Draf, Menunggu Persetujuan, Ditolak, Disetujui),
   tombol (Ajukan Persetujuan, Setuju, Tolak, Ubah, Hapus), pesan konfirmasi,
   pesan validasi, notifikasi, riwayat, dan teks audit menggunakan Bahasa
   Indonesia — tidak ada sisa teks Inggris (mis. "Submit", "Approve",
   "Reject", "Draft").

## B. Sudut Pandang Penghitung (Counter) — tanpa `adjustments.view-system-stock`

3. [ ] Buka dokumen berstatus **Draf** milik sendiri: hanya terlihat metadata
   dokumen (referensi, tanggal, lokasi, catatan) dan produk yang benar-benar
   dimasukkan (nama, kode, satuan, jumlah bagus/rusak yang dihitung, nomor
   seri yang dimasukkan beserta kondisi yang dimasukkan).
4. [ ] Pastikan TIDAK ADA: stok sistem/baseline, stok saat ini, total di
   lokasi lain, status registrasi/asal nomor seri, status pajak, selisih,
   peringatan, ringkasan peninjauan, atau proyeksi apa pun — baik di teks
   yang terlihat maupun di "Lihat Sumber Halaman" (View Source) / devtools.
5. [ ] Tombol yang tampil hanya: Ubah dan Hapus (untuk Draf), dan Ajukan
   Persetujuan (untuk Draf yang tidak kosong). Tombol Setuju/Tolak TIDAK
   pernah tampil untuk peran ini pada status apa pun.
6. [ ] Ajukan dokumen (klik "Ajukan Persetujuan"): status berubah ke
   "Menunggu Persetujuan", tombol Ubah/Hapus/Ajukan hilang.
7. [ ] Buka dokumen berstatus **Menunggu Persetujuan**: masih hanya
   menampilkan fakta yang dimasukkan (sama seperti langkah 3-4), tidak ada
   kebocoran data sistem meskipun dokumen sudah diajukan.
8. [ ] Minta peninjau menolak dokumen (lihat bagian C), lalu buka kembali
   sebagai penghitung: status "Ditolak" terlihat, alasan penolakan terlihat,
   dan dokumen dapat diubah kembali (tombol Ubah muncul).
9. [ ] Ubah dan simpan ulang dokumen yang ditolak: status kembali ke "Draf",
   riwayat penolakan sebelumnya tetap terlihat, harus diajukan ulang secara
   eksplisit sebelum dapat disetujui lagi.
10. [ ] Setelah peninjau menyetujui dokumen (lihat bagian C), buka sebagai
    penghitung: hanya terlihat jumlah bagus/rusak yang dimasukkan dan nomor
    seri yang dimasukkan beserta kondisinya — tetap tidak ada data stok
    sistem/tarif pajak/lokasi lain, meskipun dokumen sudah disetujui.

## C. Sudut Pandang Peninjau-Penyetuju — dengan `adjustments.view-system-stock` dan `adjustments.approval`

11. [ ] Buka dokumen **Menunggu Persetujuan** yang sama: terlihat "Ringkasan
    Peninjauan" berisi jumlah produk berselisih, jumlah nomor seri
    dipindah/baru, jumlah perubahan klasifikasi pajak, jumlah produk drift,
    dan jumlah konflik.
12. [ ] Untuk dokumen yang memang dibuat melebihi total stok semua lokasi:
    peringatan "potensi kenaikan stok global" tampil dengan angka yang benar.
13. [ ] Untuk dokumen yang stoknya berubah setelah baseline diambil:
    peringatan drift tampil.
14. [ ] Tabel perbandingan produk menampilkan empat kelompok kolom yang jelas:
    "Saat Mulai Dihitung", "Saat Ini" (termasuk total semua lokasi), "Hasil
    Hitung" (termasuk selisih bagus/rusak bertanda +/-), dan "Setelah
    Disetujui" (proyeksi, karena belum disetujui).
15. [ ] Klik "Lihat Rincian Seri" pada baris produk serial: bagian dapat
    dibuka/ditutup (expand/collapse) dan menampilkan, per nomor seri, status
    Bahasa Indonesia (Dipertahankan/Dipindahkan/Baru/Perubahan
    Kondisi/Perubahan Pajak/Konflik), lokasi asal dan tujuan, kondisi
    sebelum→sesudah, dan status pajak sebelum→sesudah.
16. [ ] Untuk lokasi tujuan dengan nomor seri terdaftar yang TIDAK ikut
    dimasukkan dalam hitungan: nomor seri tersebut muncul sebagai
    "Hilang saat Dihitung" dengan disposisi yang jelas.
17. [ ] Untuk dokumen dengan konflik (mis. nomor seri sedang dalam pengiriman
    aktif): konflik tampil jelas dan tombol Setuju tetap ada tetapi mengirim
    akan gagal dengan pesan Bahasa Indonesia (lihat langkah 20).
18. [ ] Sebagai akun **Peninjau tanpa `adjustments.approval`** (jika
    disiapkan): dapat melihat seluruh Ringkasan Peninjauan dan tabel
    perbandingan di atas, TETAPI tombol Setuju dan Tolak TIDAK tampil sama
    sekali. Ini membuktikan izin peninjauan dan izin persetujuan terpisah.
19. [ ] Klik "Tolak" (sebagai akun dengan `adjustments.approval`): muncul
    prompt Bahasa Indonesia meminta alasan wajib diisi; mencoba mengirim
    tanpa alasan menampilkan validasi Bahasa Indonesia dan status tidak
    berubah.
20. [ ] Klik "Setuju" pada dokumen yang punya konflik nyata (langkah 17):
    persetujuan gagal dengan pesan error Bahasa Indonesia, status tetap
    "Menunggu Persetujuan", tidak ada perubahan stok yang terlihat pada
    dokumen lain/laporan stok.
21. [ ] Klik "Setuju" pada dokumen tanpa konflik: status berubah ke
    "Disetujui", pesan sukses Bahasa Indonesia tampil.
22. [ ] Buka dokumen yang baru disetujui (langkah 21) sebagai peninjau: tabel
    perbandingan sekarang menampilkan "Sebelum Disetujui" / "Dihitung" /
    "Setelah Disetujui" berdasarkan hasil yang benar-benar diterapkan
    (bukan proyeksi), termasuk untuk produk serial (jumlah bagus/rusak harus
    terisi benar, bukan 0/0 atau tanda "-").
23. [ ] Ubah stok produk yang sama secara terpisah (mis. lewat penyesuaian
    lain atau transaksi apa pun) SETELAH dokumen di atas disetujui, lalu
    muat ulang halaman dokumen yang sudah disetujui: angka yang ditampilkan
    TIDAK berubah mengikuti stok baru — tetap menampilkan hasil yang berlaku
    saat persetujuan (approval_result yang immutable).

## D. Lintas Peran

24. [ ] Sebagai penghitung, coba akses langsung URL persetujuan/penolakan
    (`.../approve` atau `.../reject`) dokumen yang menunggu persetujuan:
    ditolak (403 atau redirect dengan pesan error Bahasa Indonesia), tidak
    ada perubahan status.
25. [ ] Sebagai peninjau-penyetuju, coba klik "Setuju" dua kali dengan cepat
    (double-click) pada dokumen yang sama: hanya satu proses persetujuan
    yang benar-benar diterapkan (tidak ada duplikasi transaksi/stok).

---

## Hasil Eksekusi

**Diisi oleh manusia setelah menjalankan checklist di atas. Jangan diisi
otomatis oleh asisten AI.**

| Tanggal | Dieksekusi oleh | Item gagal (jika ada) | Catatan |
|---|---|---|---|
| _(belum diisi)_ | _(belum diisi)_ | _(belum diisi)_ | _(belum diisi)_ |

**Status akhir: PENDING — belum ada eksekusi manusia yang tercatat.**
