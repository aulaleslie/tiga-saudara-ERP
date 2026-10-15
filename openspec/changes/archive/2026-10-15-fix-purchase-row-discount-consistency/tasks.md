# Tasks

## 1. Focused regression baseline

- [x] 1.1 Update stale discount tests to use the current cart row ID after mutations; verify `PurchaseDiscountFormattingTest` and `PurchaseProductCartDiscountTaxTest` exercise nonzero fixed/percentage discount inputs rather than unused product-ID keys.
- [x] 1.2 Add focused regressions for fixed/percentage discount persistence and converted-unit edit hydration, including explicit zero and missing legacy snapshots; verify tests reproduce the current defect and use authoritative stored factors.

## 2. Discount persistence and cart arithmetic

- [x] 2.1 Fix `PurchaseNormalizer` entered-input resolution for request and cart options, including zero and percentage semantics; verify focused normalizer tests persist Rp1,200 per BOX as Rp100 per PCS exactly once and retain 10% as entered percentage.
- [x] 2.2 Align `EditForm`, applicable controller and duplicate hydration, and cart interaction updates with entered-unit discount semantics; verify focused create/save/reopen/recalculate tests preserve or deliberately update discount snapshots without subtotal drift.
- [x] 2.3 Preserve supported fractional quantity in purchase discount/tax arithmetic and inspect any supported Alpine entry path for equivalent coercion; verify 0.500 BOX produces six PCS and Rp5,400 net subtotal in the no-tax example, with existing quantity/serial validation retained.
- [x] 2.4 Preserve manual-total authority, canonical price precision, and server conversion validation while applying the fixes; verify selected cases from `PurchaseStoredDocumentLineTotalIntegrationTest`, `PurchaseCartUomConversionTest`, and `PurchasePersistenceAndEditTest` cover unchanged totals, repeating factors, and unchanged historical snapshots.

## 3. Details and print

- [x] 3.1 Introduce or adapt purchase-specific discount presentation helpers with case-insensitive type handling and null-only snapshot fallback; verify focused helper cases distinguish percentage input, entered currency, canonical currency, explicit zero, and absent legacy snapshots.
- [x] 3.2 Correct purchase details and print discount rendering and remove the extra row-discount subtraction from taxed DPP; verify rendered-view tests show 10% with Rp1,000 equivalent on Rp10,000, Rp1,200 per BOX fixed discount, correct zero/legacy values, and DPP Rp10,800 for subtotal Rp11,988 and tax Rp1,188.

## 4. Focused flow verification

- [x] 4.1 Add a focused persisted purchase flow matrix covering fixed/percentage discounts, base/conversion units, and non-PKP/PKP included/PKP excluded modes through save, details/print, reopen, and recalculation; verify stored quantities, entered inputs, canonical discounts, totals, and tax remain consistent.
- [x] 4.2 Extend a focused receiving/approval test using saved discounted rows to verify HPP propagation across the three tax modes; verify the fixed BOX example yields Rp900 without tax or with excluded tax and Rp9,729.73 divided by twelve with included tax, within existing cost precision and with lifecycle guards retained.
- [x] 4.3 Run only the added/updated tests and selected directly affected purchase cases using explicit `php artisan test <path>` or focused `--filter` commands; record commands and outcomes and verify the final diff contains no historical repair, schema change, or unrelated behavior changes. No full-suite run is required or planned.
