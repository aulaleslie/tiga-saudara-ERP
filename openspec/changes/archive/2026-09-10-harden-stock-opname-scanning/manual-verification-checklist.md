# Panduan Verifikasi Manual Browser: Harden Stock Opname Scanning

Dokumen ini berisi panduan pengujian manual interaktif melalui browser (dengan pemindai barcode fisik atau input simulasi cepat) untuk memvalidasi ketahanan antarmuka Stock Opname setelah implementasi FIFO scan queue, sinkronisasi nilai hitung DOM/Livewire, penanganan kegagalan scan, dan pengamanan batas lokasi.

---

## Prasyarat Lingkungan Uji

1. Jalankan server aplikasi Laravel lokal (`php artisan serve` atau lingkungan dev aktif).
2. Masuk (Login) sebagai pengguna dengan izin akses Stock Opname (`adjustments.access`, `adjustments.create`, dan `adjustments.view-system-stock` jika ingin membandingkan selisih stok).
3. Pastikan pengaturan bisnis aktif (`setting_id`) memiliki setidaknya:
   - 1 Lokasi Standar yang valid (misal: "Gudang Utama").
   - 1 Lokasi Konsinyasi (misal: "Gudang Konsinyasi").
   - Produk biasa non-serial dengan barcode primer (misal: `BC-PROD-01`).
   - Produk konversi satuan (misal: konversi Dus isi 12 dengan barcode `BC-DUS-12`).
   - Produk bertipe nomor seri (misal: `BC-SERIAL-PROD`) dan nomor seri yang sudah terdaftar maupun baru.
   - 2 produk atau 1 produk & 1 serial yang memiliki kode barcode identik (untuk menguji ambiguitas).

---

## Lembar Checklist Pengujian Manual

### 1. Pemindaian Berulang Sangat Cepat (Rapid Repeated Scans)
- [ ] **Langkah**: Pilih lokasi standar "Gudang Utama". Arahkan fokus ke input pemindaian. Pindai barcode produk biasa yang sama (`BC-PROD-01`) sebanyak 5 kali secara berturut-turut dengan cepat (rapid hardware scan atau simulasi paste/Enter cepat).
- [ ] **Ekspektasi**:
  - Kolom input langsung dikosongkan setiap kali kode tertangkap, tidak terjadi penggabungan karakter/string barcode ganda (tidak concatenate).
  - Pindaian diproses berurutan (FIFO) 1 per 1.
  - Jumlah fisik barang "Bagus" pada baris produk bertambah tepat 5 unit tanpa ada hitungan yang hilang atau tertimpa.
  - Nilai yang tampil di input angka layar, model Livewire, dan draft form konsisten menunjukkan angka 5.

### 2. Pemindaian Barcode Berbeda Secara Berturut-turut (Different Back-to-Back Barcodes)
- [ ] **Langkah**: Pindai barcode Produk A, lalu dalam hitungan sepersekian detik sebelum respons Produk A selesai, langsung pindai barcode Produk B.
- [ ] **Ekspektasi**:
  - Input segera dikosongkan saat Produk A terkirim, dan Produk B masuk antrean (queue) secara bersih.
  - Produk A berhasil ditambahkan/dihitung terlebih dahulu, kemudian segera disusul oleh Produk B.
  - Kedua produk muncul di tabel masing-masing dengan kuantitas yang benar tanpa error kode tidak dikenal.

### 3. Pemindaian Barcode Konversi Satuan (Conversion Factors)
- [ ] **Langkah**: Pastikan kondisi aktif adalah "Barang Bagus". Pindai barcode konversi Dus isi 12 (`BC-DUS-12`).
- [ ] **Ekspektasi**:
  - Baris produk terkait bertambah sebanyak 12 satuan dasar.
  - Pesan umpan balik (feedback alert) berwarna hijau menampilkan penambahan `+12 satuan dasar (Dus) Bagus`.
  - Tampilan input angka dan draf dokumen mencatat penambahan 12.

### 4. Pergantian Kondisi Penghitungan (Good / Bad Switching & Immediate Scan Race)
- [ ] **Langkah**: Klik tombol toggle kondisi menjadi "Barang Rusak" (warna merah). Pindai barcode produk biasa 2 kali. Setelah itu ganti kembali ke "Barang Bagus" (warna hijau) dan pindai 1 kali.
- [ ] **Langkah Uji Timing Cepat (Click-and-Scan Immediately)**: Saat kondisi sedang "Barang Bagus", klik tombol "Barang Rusak" lalu dalam waktu instan (< 100ms, sebelum request Livewire selesai me-render ulang) langsung tekan Enter/pindai barcode produk.
- [ ] **Ekspektasi**:
  - Kolom "Hasil Hitung Rusak" bertambah 2 unit.
  - Kolom "Hasil Hitung Bagus" bertambah 1 unit.
  - Pada uji timing cepat, pindaian yang dilakukan seketika setelah tombol "Barang Rusak" diklik tertangkap dengan kondisi "Rusak" (tidak keliru masuk ke "Bagus"), membuktikan status kondisi tertangkap secara sinkron di browser sebelum request tombol selesai.
  - Kedua kolom input angka menampilkan nilai masing-masing secara akurat.

### 5. Jeda dan Lanjut Antrean pada Dialog Ambiguitas (Ambiguity Pause & Resume)
- [ ] **Langkah**: Pindai kode barcode yang ambigu (`AMB-CODE-99`), lalu segera ketik/pindai barcode lain (`BC-PROD-01`).
- [ ] **Ekspektasi**:
  - Dialog modal pilihan kandidat barcode ambigu langsung terbuka.
  - Antrean pindaian terjeda (pause) dan tidak memproses barcode berikutnya selama modal masih terbuka.
  - Pilih salah satu kandidat (atau klik Batal).
  - Segera setelah modal tertutup, antrean otomatis melanjutkan pemrosesan barcode berikutnya (`BC-PROD-01`) tanpa menjatuhkan (drop) atau mengubah urutan pemindaian.

### 6. Simulasi Kegagalan Permintaan Jaringan (Forced Request Failure & Status Unknown)
- [ ] **Langkah**: Buka Developer Tools browser (Network tab). Atur throttling menjadi "Offline" sesaat setelah menekan Enter pada pemindaian barcode, atau blokir endpoint Livewire sementara.
- [ ] **Ekspektasi**:
  - Sistem **tidak** melakukan pengiriman ulang otomatis (tidak ada auto-retry membabi-buta) karena permintaan sudah telanjur terkirim dan hasilnya di server belum tentu gagal.
  - Muncul banner peringatan merah terang dengan atribut aksesibilitas (`role="alert"`, `aria-live="assertive"`) bertuliskan pesan kegagalan dan mencantumkan kode barcode yang terdampak beserta instruksi pemulihan.
  - Antrean mengeluarkan item tersebut sehingga operator dapat memverifikasi fisik dan memindai ulang secara sadar.

### 7. Kesepakatan Nilai Layar, Model, dan Draf (Visible, Model, Draft Agreement)
- [ ] **Langkah**: Pindai beberapa produk. Buka Developer Tools console, periksa elemen `<input name="count_draft">`.
- [ ] **Ekspektasi**:
  - Nilai JSON pada `count_draft` berisi array baris produk dengan nilai `good_count` dan `bad_count` yang persis sama dengan angka yang terlihat di input form tabel.
  - Atribut HTML input tabel memiliki `value` yang mutakhir dan sesuai dengan state Livewire.

### 8. Pengeditan Manual Angka Hitung (Manual Count Edits)
- [ ] **Langkah**: Pada baris produk non-serial, ubah angka di kolom "Hasil Hitung Bagus" secara manual dengan mengetik angka (misal dari 5 menjadi 25), lalu klik di luar input (blur). Kemudian pindai produk yang berbeda.
- [ ] **Ekspektasi**:
  - Nilai 25 tetap tersimpan dan tidak tertimpa kembali menjadi 5 saat produk lain selesai dipindai.
  - Payload formulir draf tetap membawa nilai 25 saat form disubmit.

### 9. Pemulihan Fokus Kursor Otomatis (Focus Restoration)
- [ ] **Langkah**:
  1. Pindai barcode yang berhasil -> periksa apakah fokus kursor otomatis kembali ke input pemindaian.
  2. Buka modal "Cari Produk", pilih produk atau tutup modal -> periksa apakah fokus kembali ke input pemindaian.
  3. Buka modal "Kelola Nomor Seri", tambahkan serial lalu tutup modal -> periksa apakah fokus kembali ke input pemindaian.
- [ ] **Ekspektasi**:
  - Kursor selalu kembali aktif dan terseleksi di input pemindaian utama `#opname-scan-input` tanpa perlu klik mouse manual.

### 10. Percobaan Lokasi Tidak Sah / Manipulasi Lokasi (Location Boundary Enforcement)
- [ ] **Langkah**:
  1. Coba pilih lokasi konsinyasi pada dropdown lokasi.
  2. Buka console dan coba jalankan `$wire.set('locationId', ...)` ke ID lokasi lain di luar setting aktif.
  3. Pindai nomor seri yang ada di gudang/cabang lain.
- [ ] **Ekspektasi**:
  - Percobaan manipulasi properti `locationId` langsung ditolak dengan pesan error locked property Livewire.
  - Pemilihan lokasi konsinyasi atau luar setting ditolak dengan pesan bahaya "Lokasi tidak ditemukan atau bukan milik pengaturan aktif" dan dropdown dikembalikan ke lokasi sebelumnya tanpa mengekspos data baseline.
  - Nomor seri dari gudang cabang lain tetap diterima sebagai bukti fisik rekonsiliasi stok opname (dengan badge/indikator lokasi asalnya).
