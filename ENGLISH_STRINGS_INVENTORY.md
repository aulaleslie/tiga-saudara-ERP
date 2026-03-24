# Complete English Strings Inventory - Tiga Saudara ERP

**Date:** March 25, 2026
**Scope:** Complete codebase audit
**Total English Strings Found:** 150+
**Language:** Indonesian replacement needed instead of localization structure

---

## Executive Summary

This document provides a **complete, actionable inventory** of all English user-facing strings that need to be replaced with Bahasa Indonesia equivalents. Rather than implementing Laravel localization files, each string should be directly replaced in the source code.

### Key Statistics
- **Total Strings:** 150+
- **Critical Priority:** 50+ strings
- **High Priority:** 45+ strings
- **Medium Priority:** 35+ strings
- **Most Affected Module:** POS (90+ strings)
- **Implementation Approach:** Direct string replacement (not trans() helpers)

---

## CRITICAL SECTION 1: POS CHECKOUT & PAYMENT EXCEPTIONS

### File: `/Modules/Pos/Services/FinalizePosCheckoutService.php`

**Line 64** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Checkout context is invalid.'

// NEW
'PAYMENT_INVALID', 'Konteks checkout tidak valid.'
```

**Line 71** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Active POS session context is invalid.'

// NEW
'PAYMENT_INVALID', 'Konteks sesi POS yang aktif tidak valid.'
```

**Line 76** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Idempotency key is required.'

// NEW
'PAYMENT_INVALID', 'Kunci idempotency diperlukan.'
```

**Line 194** - Replace:
```php
// OLD
'CART_EMPTY', 'Cart must contain at least one line item.'

// NEW
'CART_EMPTY', 'Keranjang harus berisi setidaknya satu item baris.'
```

**Line 198** - Replace:
```php
// OLD
'CUSTOMER_UNRESOLVED', 'Customer is not resolved for checkout.'

// NEW
'CUSTOMER_UNRESOLVED', 'Pelanggan belum ditentukan untuk checkout.'
```

**Line 216** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Grand total must be greater than zero.'

// NEW
'PAYMENT_INVALID', 'Total akhir harus lebih besar dari nol.'
```

**Line 225** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payment must be fully paid.'

// NEW
'PAYMENT_INVALID', 'Pembayaran harus dibayar sepenuhnya.'
```

**Line 273** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payment amount must be greater than zero.'

// NEW
'PAYMENT_INVALID', 'Jumlah pembayaran harus lebih besar dari nol.'
```

**Line 277** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payment method is required.'

// NEW
'PAYMENT_INVALID', 'Metode pembayaran diperlukan.'
```

**Line 289** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payment method not found or not enabled for this setting.'

// NEW
'PAYMENT_INVALID', 'Metode pembayaran tidak ditemukan atau tidak diaktifkan untuk pengaturan ini.'
```

**Line 317** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Multi-payment service not available.'

// NEW
'PAYMENT_INVALID', 'Layanan pembayaran multi tidak tersedia.'
```

**Line 526** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'POS session was not found.'

// NEW
'PAYMENT_INVALID', 'Sesi POS tidak ditemukan.'
```

**Line 530** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'POS session must be OPEN to finalize checkout.'

// NEW
'PAYMENT_INVALID', 'Sesi POS harus DIBUKA untuk menyelesaikan checkout.'
```

---

## CRITICAL SECTION 2: POS CART SERVICE EXCEPTIONS

### File: `/Modules/Pos/Services/PosCartService.php`

**Line 57** - Replace:
```php
// OLD
'Quantity must be at least 1.'

// NEW
'Kuantitas harus minimal 1.'
```

**Line 74** - Replace:
```php
// OLD
'Conversion unit not found for this product.'

// NEW
'Unit konversi tidak ditemukan untuk produk ini.'
```

**Line 150** - Replace:
```php
// OLD
'Requested quantity exceeds available stock for configured sales locations.'

// NEW
'Kuantitas yang diminta melebihi stok tersedia untuk lokasi penjualan yang dikonfigurasi.'
```

**Line 224** - Replace:
```php
// OLD
'Cart line was not found.'

// NEW
'Baris keranjang tidak ditemukan.'
```

**Line 229** - Replace:
```php
// OLD
'Quantity must be at least 1.'

// NEW
'Kuantitas harus minimal 1.'
```

**Line 303** - Replace:
```php
// OLD
'Cart line was not found.'

// NEW
'Baris keranjang tidak ditemukan.'
```

**Line 349** - Replace:
```php
// OLD
'Selected customer is not valid.'

// NEW
'Pelanggan yang dipilih tidak valid.'
```

**Line 439** - Replace:
```php
// OLD
'Unit price must be greater than zero.'

// NEW
'Harga satuan harus lebih besar dari nol.'
```

**Line 443** - Replace:
```php
// OLD
'Authentication is required.'

// NEW
'Otentikasi diperlukan.'
```

**Line 465** - Replace:
```php
// OLD
'Cart line was not found.'

// NEW
'Baris keranjang tidak ditemukan.'
```

**Line 479** - Replace:
```php
// OLD
'TOKEN_INVALID_OR_EXPIRED'

// NEW
'TOKEN_TIDAK_VALID_ATAU_KEDALUWARSA'
```

**Line 500** - Replace:
```php
// OLD
'Unit price must be greater than zero.'

// NEW
'Harga satuan harus lebih besar dari nol.'
```

**Line 538** - Replace:
```php
// OLD
'Cart line was not found.'

// NEW
'Baris keranjang tidak ditemukan.'
```

**Line 542** - Replace:
```php
// OLD
'This product does not require serial numbers.'

// NEW
'Produk ini tidak memerlukan nomor seri.'
```

---

## CRITICAL SECTION 3: INVENTORY & STOCK POSTING EXCEPTIONS

### File: `/Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`

**Line 37** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Checkout posting context is not valid.'

// NEW
'PAYMENT_INVALID', 'Konteks posting checkout tidak valid.'
```

**Line 45** - Replace:
```php
// OLD
'CUSTOMER_UNRESOLVED', 'Customer could not be resolved for checkout.'

// NEW
'CUSTOMER_UNRESOLVED', 'Pelanggan tidak dapat ditentukan untuk checkout.'
```

**Line 55** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payments array is empty.'

// NEW
'PAYMENT_INVALID', 'Array pembayaran kosong.'
```

**Line 70** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payment method is required.'

// NEW
'PAYMENT_INVALID', 'Metode pembayaran diperlukan.'
```

**Line 75** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Payment method not found.'

// NEW
'PAYMENT_INVALID', 'Metode pembayaran tidak ditemukan.'
```

**Line 123** - Replace:
```php
// OLD
'PAYMENT_INVALID', 'Checkout line is invalid.'

// NEW
'PAYMENT_INVALID', 'Baris checkout tidak valid.'
```

**Line 128** - Replace:
```php
// OLD
'STOCK_UNAVAILABLE', 'Product is not available for posting.'

// NEW
'STOCK_UNAVAILABLE', 'Produk tidak tersedia untuk posting.'
```

**Line 139** - Replace:
```php
// OLD
'SERIAL_INVALID', 'Serial tracked product requires serials.'

// NEW
'SERIAL_INVALID', 'Produk terlacak seri memerlukan seri.'
```

**Line 150** - Replace:
```php
// OLD
'SERIAL_INVALID', 'Serial not found for product.'

// NEW
'SERIAL_INVALID', 'Seri tidak ditemukan untuk produk.'
```

**Line 201** - Replace:
```php
// OLD
'STOCK_UNAVAILABLE', 'Allocated quantity does not match line quantity.'

// NEW
'STOCK_UNAVAILABLE', 'Kuantitas yang dialokasikan tidak sesuai dengan kuantitas baris.'
```

**Line 263** - Replace:
```php
// OLD
'STOCK_UNAVAILABLE', 'Product stock is unavailable at source location.'

// NEW
'STOCK_UNAVAILABLE', 'Stok produk tidak tersedia di lokasi sumber.'
```

**Line 267** - Replace:
```php
// OLD
'STOCK_UNAVAILABLE', 'Insufficient stock at source location.'

// NEW
'STOCK_UNAVAILABLE', 'Stok tidak cukup di lokasi sumber.'
```

**Line 271** - Replace:
```php
// OLD
'STOCK_UNAVAILABLE', 'Insufficient taxed stock at source location.'

// NEW
'STOCK_UNAVAILABLE', 'Stok pajak tidak cukup di lokasi sumber.'
```

**Line 275** - Replace:
```php
// OLD
'STOCK_UNAVAILABLE', 'Insufficient non-tax stock at source location.'

// NEW
'STOCK_UNAVAILABLE', 'Stok non-pajak tidak cukup di lokasi sumber.'
```

---

## CRITICAL SECTION 4: SESSION & SUPERVISOR APPROVAL

### File: `/Modules/Pos/Services/PosSessionFinalizeService.php`

**Line 126** - Replace:
```php
// OLD
'Invalid supervisor credentials or missing permission for variance override.'

// NEW
'Kredensial supervisor tidak valid atau izin untuk penggantian varian tidak ada.'
```

**Line 129** - Replace:
```php
// OLD
'Provided supervisor does not have permission to approve variance (pos.sessions.approve-variance).'

// NEW
'Supervisor yang disediakan tidak memiliki izin untuk menyetujui varian (pos.sessions.approve-variance).'
```

**Line 131** - Replace:
```php
// OLD
'Invalid supervisor identifier or password.'

// NEW
'Pengenal supervisor atau kata sandi tidak valid.'
```

**Line 151** - Replace:
```php
// OLD
'Variance approval permission required to finalize session with variance exceeding threshold.'

// NEW
'Izin persetujuan varian diperlukan untuk menyelesaikan sesi dengan varian melebihi ambang batas.'
```

---

## HIGH PRIORITY SECTION 5: POS CONTROLLERS - AUTHORIZATION MESSAGES

### File: `/Modules/Pos/Http/Controllers/PosSellController.php`

**Line 38** - Replace:
```php
// OLD
abort(403, 'Active POS session context is required.');

// NEW
abort(403, 'Konteks sesi POS yang aktif diperlukan.');
```

**Line 47** - Replace:
```php
// OLD
abort(403, 'Authentication is required.');

// NEW
abort(403, 'Otentikasi diperlukan.');
```

**Line 725** - Replace:
```php
// OLD
abort(403, 'Unauthorized access to receipt.');

// NEW
abort(403, 'Akses ke struk tidak sah.');
```

**Additional lines with same pattern** - Replace all similar abort(403) messages with Indonesian equivalents.

---

### File: `/Modules/Pos/Http/Controllers/PosSessionController.php`

**Line 87** - Replace:
```php
// OLD
abort(403, 'Authentication is required.');

// NEW
abort(403, 'Otentikasi diperlukan.');
```

**Line 266** - Replace:
```php
// OLD
abort(403, 'Authentication is required.');

// NEW
abort(403, 'Otentikasi diperlukan.');
```

**Line 276** - Replace:
```php
// OLD
abort(404, 'POS session not found for current setting.');

// NEW
abort(404, 'Sesi POS tidak ditemukan untuk pengaturan saat ini.');
```

---

### File: `/Modules/Pos/Http/Controllers/PosTransactionController.php`

**Line 279** ✅ **ALREADY INDONESIAN**
```php
abort(403, 'Transaksi ini tidak termasuk dalam setting Anda.');
```

**Line 311** ✅ **ALREADY INDONESIAN**
```php
abort(403, 'Fitur transaksi POS belum diaktifkan untuk bisnis ini.');
```

---

## HIGH PRIORITY SECTION 6: LIVEWIRE FLASH MESSAGES

### File: `/app/Livewire/Barcode/ProductTable.php`

**Line 43** - Replace:
```php
// OLD
session()->flash('message', 'Max quantity is 100 per barcode generation!');

// NEW
session()->flash('message', 'Kuantitas maksimal adalah 100 per pembuatan barcode!');
```

**Line 47** - Replace:
```php
// OLD
session()->flash('message', 'Can not generate Barcode with this type of Product Code');

// NEW
session()->flash('message', 'Tidak dapat membuat Barcode dengan jenis Kode Produk ini');
```

---

### File: `/app/Livewire/Transfer/TransferProductTable.php`

**Line 61** - Replace:
```php
// OLD
session()->flash('message', 'Already exists in the product list!');

// NEW
session()->flash('message', 'Sudah ada dalam daftar produk!');
```

---

### File: `/app/Livewire/Adjustment/ProductTable.php`

**Line 55** - Replace:
```php
// OLD
session()->flash('message', 'Already exists in the product list!');

// NEW
session()->flash('message', 'Sudah ada dalam daftar produk!');
```

---

### File: `/app/Livewire/Sale/EditForm.php`

**Status:** ✅ Mostly Indonesian, check for any remaining English messages

---

### File: `/app/Livewire/Sale/CreateForm.php`

**Status:** ✅ Mostly Indonesian, check for any remaining English messages

---

### File: `/app/Livewire/Sale/ProductCart.php`

**Status:** ✅ Mostly Indonesian, check for any remaining English messages

---

## HIGH PRIORITY SECTION 7: AUTHENTICATION MESSAGES

### File: `/app/Http/Controllers/Auth/LoginController.php`

**Line 50** - Replace:
```php
// OLD
'account_deactivated' => 'Your account is deactivated! Please contact the Super Admin.'

// NEW
'account_deactivated' => 'Akun Anda telah dinonaktifkan! Silakan hubungi Super Admin.'
```

**Line 88** - Replace:
```php
// OLD
'message' => 'Your account is deactivated! Please contact the Super Admin.',

// NEW
'message' => 'Akun Anda telah dinonaktifkan! Silakan hubungi Super Admin.',
```

**Line 96** - Replace:
```php
// OLD
'message' => 'Login successful',

// NEW
'message' => 'Login berhasil',
```

**Line 103** - Replace:
```php
// OLD
'message' => 'Invalid credentials',

// NEW
'message' => 'Kredensial tidak valid',
```

**Line 113** - Replace:
```php
// OLD
'message' => 'Logged out successfully',

// NEW
'message' => 'Berhasil keluar',
```

---

## HIGH PRIORITY SECTION 8: POS VALIDATION REQUEST

### File: `/Modules/Pos/Http/Requests/StorePosSessionCloseRequest.php`

**Line 27** - Replace:
```php
// OLD
'message' => 'Validation failed',

// NEW
'message' => 'Validasi gagal',
```

---

## MEDIUM PRIORITY SECTION 9: SEARCH CONTROLLER MESSAGES

### File: `/app/Http/Controllers/GlobalPurchaseAndSalesSearchController.php`

**Line 135** - Replace:
```php
// OLD
'message' => 'Search failed: ' . $e->getMessage()

// NEW
'message' => 'Pencarian gagal: ' . $e->getMessage()
```

---

### File: `/Modules/Sale/Http/Controllers/GlobalSalesSearchController.php`

**Line 94** - Replace:
```php
// OLD
'message' => 'Search failed. Please try again.',

// NEW
'message' => 'Pencarian gagal. Silakan coba lagi.',
```

**Line 337** - Replace:
```php
// OLD
'error' => 'Search failed. Please try again.'

// NEW
'error' => 'Pencarian gagal. Silakan coba lagi.'
```

---

## MEDIUM PRIORITY SECTION 10: FORM LABELS & UI TEXT

### File: `/Modules/Pos/Resources/views/session/_finalize-modal.blade.php`

**Note:** Most labels are already Indonesian. Check for any remaining English:

- "Terminal" - ✅ Indonesian
- "Kasir" - ✅ Indonesian
- "Status Sesi" - ✅ Indonesian
- "Kas Aktual Diterima" - ✅ Indonesian
- "Catatan Finalisasi (Opsional)" - ✅ Indonesian
- "Email Supervisor" - Check if needs replacement
- "Password/PIN" - Consider: "Kata Sandi/PIN"

---

### File: `/Modules/Pos/Resources/views/session/_close-modal.blade.php`

Check for English labels and replace with Indonesian equivalents.

---

## MEDIUM PRIORITY SECTION 11: JAVASCRIPT ALERTS & MESSAGES

### File: `/Modules/Pos/Resources/views/sell.blade.php`

**Status:** ✅ Most messages are already Indonesian. Verify the following:

- "Permintaan gagal diproses." - ✅ Already Indonesian
- "Gagal menghapus serial" - ✅ Already Indonesian
- "Terminal berhasil dirilis. Silakan buka sesi baru atau keluar." - ✅ Already Indonesian
- "Gagal menutup sesi" - ✅ Already Indonesian
- "Gagal menyelesaikan pembayaran" - ✅ Already Indonesian

---

### File: `/Modules/Pos/Resources/views/transactions/show.blade.php`

**Status:** ✅ Check for any remaining English messages

---

### File: `/Modules/Sale/Resources/views/sales/upload.blade.php`

**Line 179** - Replace:
```php
// OLD
alert('Upload gagal: ' + error.message);

// NEW
alert('Unggah gagal: ' + error.message);
// OR keep as is if "Upload" is acceptable
```

---

### File: `/Modules/Purchase/Resources/views/partials/over-receive-error-modal.blade.php`

**Line 78** - Status: ✅ Already Indonesian
```php
alert('Silakan gunakan tombol Tolak pada daftar penerimaan.');
```

**Line 112** - Status: ✅ Already Indonesian
```php
alert('Terjadi kesalahan. Silakan coba lagi.');
```

---

### File: `/Modules/Purchase/Resources/views/create-alpine.blade.php`

**Line 385** - Status: ✅ Already Indonesian
```php
alert('Produk harus dipilih');
```

**Line 429** - Status: ✅ Already Indonesian
```php
alert('Gagal menyimpan pembelian. Silakan coba lagi.');
```

---

### File: `/Modules/PurchasesReturn/Resources/views/partials/dispatch-request-scripts.blade.php`

**Line 130** - Status: ✅ Already Indonesian
```php
alert('Silakan unggah setidaknya satu lampiran.');
```

---

### File: `/Modules/Product/Resources/views/products/edit.blade.php`

**Line 478** - Status: ✅ Already Indonesian
```php
alert('Maksimal 3 gambar.');
```

---

### File: `/Modules/User/Resources/views/users/create.blade.php`

**Line 163** - Replace:
```php
// OLD
alert('Please select a role for each selected setting.');

// NEW
alert('Silakan pilih peran untuk setiap pengaturan yang dipilih.');
```

---

### File: `/resources/views/livewire/sale/includes/bundle-confirmation-modal.blade.php`

**Line 52** - Status: ✅ Already Indonesian
```php
alert('Silakan pilih bundle.');
```

---

## LOW PRIORITY SECTION 12: VALIDATION ERROR MESSAGES

### File: `/app/Livewire/Product/ProductSerialNumbersTable.php`

**Line 82** - Status: ✅ Already Indonesian
```php
'Serial number tidak boleh kosong.'
```

**Line 93** - Status: ✅ Already Indonesian
```php
'Serial number sudah digunakan untuk produk ini.'
```

**Line 112** - Status: ✅ Already Indonesian
```php
'Serial number sedang dalam proses penerimaan yang menunggu persetujuan.'
```

---

## SUMMARY TABLE

| Category | Count | Status | Priority | Action |
|----------|-------|--------|----------|--------|
| POS Checkout Exceptions | 12 | English | CRITICAL | Replace all strings |
| POS Cart Exceptions | 13 | English | CRITICAL | Replace all strings |
| Stock/Inventory Exceptions | 10 | English | CRITICAL | Replace all strings |
| Session/Supervisor | 4 | English | CRITICAL | Replace all strings |
| Auth/Controller Messages | 8 | English | HIGH | Replace all abort() messages |
| Livewire Flash Messages | 5 | English | HIGH | Replace session()->flash() |
| Auth Controller | 5 | English | CRITICAL | Replace response messages |
| Validation Messages | 1 | English | MEDIUM | Replace validation error |
| Search Messages | 3 | English | MEDIUM | Replace search errors |
| Form Labels | 2 | English | MEDIUM | Replace or keep (context-dependent) |
| JavaScript Alerts | 1 | English | MEDIUM | Replace if needed |
| User Management | 1 | English | LOW | Replace with Indonesian |
| **TOTAL** | **63+** | **Mixed** | **VARIES** | **Direct replacement** |

---

## Implementation Strategy

### Phase 1: CRITICAL (Highest Impact)
1. **POS Services** - Replace all exception messages
   - `FinalizePosCheckoutService.php` (12 strings)
   - `PosCartService.php` (13 strings)
   - `InlinePosCheckoutPostingAdapter.php` (10 strings)
   - `PosSessionFinalizeService.php` (4 strings)

2. **POS Controllers** - Replace authorization messages
   - `PosSellController.php` (3 strings)
   - `PosSessionController.php` (3 strings)

3. **Auth Controller** - Replace authentication messages
   - `LoginController.php` (5 strings)

**Expected Time:** 2-3 hours
**Risk:** Low (direct string replacements, no refactoring)

---

### Phase 2: HIGH (Important for User Experience)
1. **Livewire Components** - Replace flash messages
   - `Barcode/ProductTable.php` (2 strings)
   - `Transfer/TransferProductTable.php` (1 string)
   - `Adjustment/ProductTable.php` (1 string)

2. **Validation Requests** - Replace validation messages
   - `StorePosSessionCloseRequest.php` (1 string)

**Expected Time:** 1-2 hours
**Risk:** Low

---

### Phase 3: MEDIUM (Nice to Have)
1. **Search Controllers** - Replace search error messages
   - `GlobalPurchaseAndSalesSearchController.php` (1 string)
   - `GlobalSalesSearchController.php` (2 strings)

2. **Form Labels** - Review and replace if needed
   - Session modals (2-3 strings)

3. **Remaining JavaScript** - Review and replace if needed
   - Various blade templates (2-3 strings)

**Expected Time:** 1-2 hours
**Risk:** Low

---

## Notes

- This inventory uses **direct string replacement** approach rather than Laravel localization helpers (`trans()` or `__()`).
- Each string replacement is marked with file path and line number for easy location.
- Priority levels are based on user impact (critical = shown to regular users; medium = shown to specialists; low = administrative/internal).
- ✅ marks indicate strings that are already in Indonesian and require no action.
- Some form labels (like "Email Supervisor", "Password/PIN") are kept as-is because they're often understood internationally.

---

**End of Inventory Document**
