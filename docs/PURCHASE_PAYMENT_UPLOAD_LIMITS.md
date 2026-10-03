# Panduan Konfigurasi Batas Unggah (PHP & Reverse Proxy)

Dokumen ini menjelaskan konfigurasi deployment server (PHP dan Reverse Proxy seperti Nginx / Apache / Cloudflare) untuk mendukung fitur unggah beberapa lampiran pada modul Pembayaran Pembelian (`Individual Purchase Payment Attachments`).

---

## 1. Konfigurasi PHP (`php.ini`)

Aplikasi ERP tidak membatasi ukuran awal berkas lampiran secara artifisial, namun PHP memberlakukan batas ukuran unggah bawaan melalui direktif `php.ini`. Pastikan parameter berikut disesuaikan dengan kebutuhan operasional:

```ini
; Batas ukuran per berkas yang diunggah
upload_max_filesize = 20M

; Batas ukuran seluruh payload POST (harus >= upload_max_filesize)
post_max_size = 25M

; Batas memori untuk pemrosesan script (terutama saat kompresi berkas gambar)
memory_limit = 256M

; Batas waktu eksekusi skrip untuk unggah file besar
max_execution_time = 120
max_input_time = 120
```

### Ekstensi PHP yang Wajib Aktif
- `ext-fileinfo`: Untuk verifikasi MIME type berkas.
- `ext-zip` (`ZipArchive`): Untuk verifikasi integritas paket format dokumen Office OpenXML (`.docx` dan `.xlsx`). Jika ekstensi ini tidak tersedia, unggahan dan penyimpanan berkas DOCX/XLSX akan ditolak demi keamanan (fail-closed).
- `ext-gd` atau `ext-imagick`: Untuk pemrosesan dan optimasi berkas gambar.

Setelah mengubah `php.ini`, muat ulang layanan PHP-FPM:
```bash
sudo systemctl reload php8.1-fpm # sesuaikan dengan versi PHP yang aktif
```

---

## 2. Konfigurasi Reverse Proxy (Nginx)

Bawaan Nginx membatasi ukuran body permintaan sebesar 1MB (`client_max_body_size 1m;`), yang dapat menyebabkan respon HTTP `413 Request Entity Too Large` sebelum permintaan mencapai Laravel.

Tambahkan atau perbarui konfigurasi pada blok `server` atau `http` Nginx:

```nginx
server {
    listen 80;
    server_name erp.example.com;

    # Sesuaikan dengan batas upload yang diinginkan (minimal sama dengan post_max_size)
    client_max_body_size 25M;

    # ... konfigurasi lainnya ...
}
```

Uji dan muat ulang Nginx:
```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 3. Konfigurasi Apache (Jika Menggunakan Apache HTTP Server)

Jika aplikasi berjalan di Apache (misalnya via `mod_php` atau proxy FCGI):

Tambahkan arahan pada file virtual host atau `.htaccess`:
```apache
LimitRequestBody 26214400 # 25 MB dalam bytes (25 * 1024 * 1024)
```

---

## 4. Konfigurasi Cloudflare / CDN (Jika Digunakan)

Jika domain berada di balik Cloudflare Free/Pro plan, batas maksimum upload per permintaan adalah 100 MB (atau paket Enterprise hingga 500 MB). Tidak ada konfigurasi khusus yang diperlukan selama ukuran file berada di bawah batas rencana CDN Anda.
