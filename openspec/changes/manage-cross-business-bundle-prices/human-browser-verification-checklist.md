# Human Browser Verification Checklist: Manage Cross-Business Bundle Prices

This checklist serves as the human browser verification protocol for validating the cross-business bundle price matrix, layout, and lifecycle behaviors.

## Target URL
`/products/{product_id}/cross-business-prices` (accessible via Product list action menu with `products.manage_cross_business_prices` permission).

---

### 1. Matrix Layout & Horizontal Responsiveness
- [ ] **Section 3 Presence**: Verify Section 3 titled `Harga Paket` renders beneath Section 1 (`Harga Produk`) and Section 2 (`Harga Satuan Konversi`).
- [ ] **Empty State**: Navigate to a product without grouped bundles (`replica_group_uuid IS NOT NULL`). Verify the section displays a clean alert (`Produk ini belum memiliki grup paket yang terdaftar.`).
- [ ] **Column Headers**: For products with grouped bundles, verify each replica group is rendered as a distinct column header displaying its representative name (e.g. `<Nama Paket> (Rp)`).
- [ ] **Divergent Names Indicator**: If bundle copies in the same replica group have different names across businesses, verify the column header includes the helper notice `Nama paket berbeda di beberapa bisnis`.
- [ ] **Horizontal Scroll**: On smaller screens or when multiple bundle groups exist, confirm the table is wrapped inside Bootstrap's scrollable container (`table-responsive`) with clean layout and clear column alignment.

---

### 2. Status Badges & Unavailable Cells
- [ ] **Inactive Bundle Copies**: If an existing bundle has `is_active = false`, verify the badge `Tidak aktif` displays above the price input in that specific cell.
- [ ] **Missing Bundle Copies (Unavailable Cells)**: When a business does not have a copy in that replica group, verify the cell renders a gray muted label `Paket tidak tersedia`.
- [ ] **Unavailable Cell Immutability**: Confirm unavailable cells have no input fields, no hidden payload inputs, and do not change to edit mode when **Ubah** is clicked.

---

### 3. Read-Only / Ubah / Batal Flow & Currency Masking
- [ ] **Initial State**: On page load, all existing bundle price inputs must be `readonly` and formatted in Indonesian locale currency (e.g., `45.000,00` or `45.000`).
- [ ] **Click "Ubah" (Edit)**:
  - The **Ubah** button toggles to **Batal** and the **Simpan Perubahan** button appears.
  - Existing bundle price inputs become editable (`readonly` removed).
  - Focusing on an input converts it to raw canonical format (e.g., `45000` or `45000.50`) for easy typing.
  - Blurring outside re-formats the value to locale display with thousand separators.
- [ ] **Dirty Detection**: Editing a bundle price highlights the row / input with the dirty state visual indicator.
- [ ] **Click "Batal" (Cancel)**:
  - The form reverts to read-only mode.
  - All edited values restore to their exact initial loaded values and thousand separators.
  - Dirty indicators clear.

---

### 4. Group-Scoped "Terapkan ke Semua" (Apply-to-All)
- [ ] **Action Scope**: In edit mode, clicking the **Terapkan ke Semua** button (`btn-apply-all-bundle`) next to a bundle price input copies that input's current raw value to all *existing* bundle cells within that specific replica group column.
- [ ] **Preserve Unavailable**: Confirm that missing (`Paket tidak tersedia`) cells in the same column remain unavailable and are not populated or converted to inputs.
- [ ] **Preserve Other Columns**: Confirm that bundle inputs in other replica group columns remain completely untouched.

---

### 5. Submission, Optimistic Locking & Validation
- [ ] **Form Submission**: Click **Simpan Perubahan** with valid prices; verify success redirect and that raw unmasked decimals are persisted correctly.
- [ ] **Unchanged Bundle Stability**: Verify that saving a form where only one bundle group was modified does NOT update `updated_at` on untouched bundle copies.
- [ ] **Validation Redisplay**: If invalid input is submitted (e.g., negative value), verify the page re-renders with server-side validation error messages and previous inputs restored.
- [ ] **Optimistic Lock Conflict**: If a bundle or price is updated concurrently in another tab/session, submitting the form displays the conflict warning ("Data harga paket telah diperbarui oleh pengguna lain. Silakan muat ulang halaman.") and rolls back changes without saving partial data.
