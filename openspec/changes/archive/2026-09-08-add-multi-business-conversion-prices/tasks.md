## 1. Load and validate conversion pricing

- [x] 1.1 Extend CrossBusinessPriceService and the edit controller to load the base unit, shared conversion headers, and all business cells with explicit missing-state metadata using eager loading.
- [x] 1.2 Generate product-bound signed loaded-state evidence covering businesses, conversion structure, and conversion price presence, values, and versions.
- [x] 1.3 Extend CrossBusinessPriceUpdateRequest validation for unique product-owned conversion/business identities, the complete matrix, non-negative two-decimal prices, and blank-only-if-originally-missing semantics.

## 2. Save safely and preserve shared conversion behavior

- [x] 2.1 Extend the existing save transaction to validate loaded-state evidence and persist base and conversion prices atomically, rejecting changed structure, membership, prices, and row presence.
- [x] 2.2 Coordinate cross-business save and product-edit conversion mutations with a consistent product-row lock and deterministic lock order; preserve existing base-price version checks and feed recording.
- [x] 2.3 Update only conversion price values on existing rows, preserve enablement flags, create explicitly configured missing rows with existing defaults, and leave untouched missing cells absent.
- [x] 2.4 Confirm product-edit shared unit/factor updates, initial price seeding, and global deletion remain intact; retain other businesses' numeric prices after existing conversion edits and add explanatory product-edit text.

## 3. Build the two-section price page

- [x] 3.1 Label the existing table Harga Satuan Dasar with the base unit, and add the responsive Harga Satuan Konversi matrix with unit/factor headings and a no-conversions empty state.
- [x] 3.2 Integrate conversion fields into Ubah/Batal/Simpan, decimal formatting, validation restoration, duplicate-submit prevention, and missing-state rendering.
- [x] 3.3 Add conversion-ID-scoped apply-to-all controls with numeric dirty-state detection; ensure copying does not modify another conversion or base column and Cancel restores missing states.

## 4. Focused verification

- [x] 4.1 Add or extend focused Product backend/rendering tests for permissions, matrix headers, no conversions, independent prices, missing versus zero, decimals, and preservation of flags, averages, and tax metadata.
- [x] 4.2 Add focused service/request tests for malformed identities, invalid prices, atomic rollback, tampered evidence, stale conversion factors/membership, and concurrent conversion-price changes including row creation/deletion.
- [x] 4.3 Extend ProductUnitConversionPriceTest coverage for changed shared units/factors with unchanged other-business prices, and confirm new-conversion seeding and global deletion.
- [x] 4.4 Run only the affected test classes or method filters against an isolated test database, plus relevant PHP syntax checks; record results without running the full suite or automated browser tests.
- [x] 4.5 Prepare and hand off a human-only browser checklist covering responsive conversion columns, Ubah/Batal/Simpan, decimal input, missing cells, same-conversion copying, and stale-factor feedback using disposable data. Record human execution as pending unless results are supplied; this task is complete when the checklist is handed off.

