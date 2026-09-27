## Context

Saat ini alur POS membuka staged payment segera ketika tombol `Pilih Pembayaran` diklik. Validasi kebenaran stok/serial yang authoritative berada di backend finalize checkout, sehingga mismatch (contoh qty kasir 2 tetapi stok aktual tersisa 1) bisa baru muncul setelah user masuk dan berinteraksi di alur pembayaran. Ini menyebabkan UX menyesatkan karena user melihat progres pembayaran lebih dulu sebelum ditolak.

Perubahan ini menyentuh beberapa layer sekaligus: wiring frontend POS shell, kontrak API checkout, dan reuse logic validasi backend yang sebelumnya terikat pada finalize flow.

## Goals / Non-Goals

**Goals:**
- Menambahkan gate preflight sebelum staged payment modal dibuka.
- Mengembalikan detail mismatch terstruktur agar UI bisa menampilkan dialog yang jelas per baris.
- Menjaga satu sumber validasi untuk serial/stock (hindari duplikasi rule antara preflight vs finalize).
- Memastikan state UX: modal staged tidak boleh muncul jika preflight gagal.

**Non-Goals:**
- Mendesain ulang seluruh staged payment UX atau mengganti model pembayaran bertahap.
- Mengubah aturan bisnis approval qty reduction.
- Mengubah mekanisme posting stok saat finalize berhasil.

## Decisions

### 1. Tambah endpoint preflight checkout terpisah
- **Decision:** Tambahkan endpoint POS checkout preflight yang memvalidasi cart terhadap aturan serial/stock sebelum membuka modal pembayaran.
- **Rationale:** Tombol `Pilih Pembayaran` membutuhkan jawaban cepat "boleh lanjut atau tidak" tanpa side effect pembayaran/finalize.
- **Alternatives considered:**
  - Panggil `/checkout/finalize` sebagai probe: ditolak karena side effect idempotency/record checkout.
  - Validasi murni di frontend dari snapshot: ditolak karena tidak authoritative untuk drift stok real-time.

### 2. Reuse validator finalize-grade melalui service method bersama
- **Decision:** Ekstrak/ekspos jalur validasi dari service checkout agar bisa dipakai oleh `preflight` dan `finalize`.
- **Rationale:** Mencegah divergence antara hasil preflight dan hasil finalize.
- **Alternatives considered:**
  - Duplikasi logic di controller preflight: cepat tapi berisiko inkonsisten dan regression.

### 3. Error contract preflight bersifat structured
- **Decision:** Response gagal preflight harus menyertakan `code`, `message`, dan `details.unfulfilled_lines[]` (line index, product, reason code, requested vs available bila relevan).
- **Rationale:** UI membutuhkan data granular untuk mismatch modal, bukan string error generik.
- **Alternatives considered:**
  - Hanya kirim message text: lebih sederhana tapi tidak cukup untuk dialog detail.

### 4. Frontend gate di click handler `Pilih Pembayaran`
- **Decision:** Handler checkout menjalankan preflight async, baru memanggil `PosStagedPayment.openModal(...)` jika preflight pass.
- **Rationale:** Paling dekat dengan intent user dan mudah mencegah modal staged terbuka prematur.
- **Alternatives considered:**
  - Gate di `PosStagedPayment.openModal`: masih mungkin dipanggil dari entry point lain tanpa context cart shell.

### 5. Dedicated mismatch modal + return to cart context
- **Decision:** Tambahkan modal mismatch yang menampilkan daftar item bermasalah. Saat ditutup, user tetap di POS cart tanpa membuka staged modal.
- **Rationale:** Sesuai kebutuhan operasional kasir untuk koreksi cepat.
- **Alternatives considered:**
  - Hanya status bar error di bawah cart: kurang terlihat untuk multi-line mismatch.

## Risks / Trade-offs

- **[Risk]** Preflight pass tetapi finalize gagal karena race stok sesudah preflight → **Mitigation:** pertahankan finalize validation sebagai authoritative guard dan tampilkan error finalize yang konsisten.
- **[Risk]** Payload error backend berubah dan memutus rendering modal → **Mitigation:** definisikan contract minimal stabil (`code`, `message`, `details.unfulfilled_lines`) dan fallback UI message.
- **[Risk]** Tambahan request sebelum modal meningkatkan latency klik checkout → **Mitigation:** endpoint preflight read-only, query minimum, dan tampilkan loading state singkat di tombol checkout.

## Migration Plan

1. Tambahkan endpoint dan service preflight tanpa mengubah flow lama finalize.
2. Ubah frontend click flow untuk memanggil preflight dulu.
3. Tambahkan mismatch modal dan renderer details.
4. Pastikan finalize tetap memvalidasi ulang (no trust on preflight).
5. Rollout bertahap dengan regression tests untuk staged flow dan stock mismatch.

Rollback:
- Kembalikan wiring frontend ke direct open staged modal.
- Nonaktifkan route preflight (atau biarkan idle) tanpa mempengaruhi finalize.

## Open Questions

- Apakah mismatch modal perlu aksi cepat langsung (contoh "Kurangi ke stok tersedia") atau cukup informasi + tutup?
- Untuk reason code non-stock (mis. customer unresolved), apakah tetap tampil dalam mismatch modal yang sama atau fallback ke status error umum?
- Perlukah preflight dipanggil ulang otomatis setelah user menutup mismatch modal dan melakukan edit qty/serial?
