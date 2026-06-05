## 1. Test Coverage

- [x] 1.1 Update purchase import tests proving `aries` routes to `TIGA COMPUTER`.
- [x] 1.2 Update purchase import tests proving `rahmat` routes to `WHITE KNIGHT COMPUTER`.
- [x] 1.3 Update purchase import tests proving `agus` routes to `DUNIA COMPUTER`.
- [x] 1.4 Add or confirm purchase import tests proving `perdana` routes to `PERDANA`.
- [x] 1.5 Add or confirm purchase import tests proving blank and unmapped tags still route to PERDANA.
- [x] 1.6 Add or confirm duplicate-check coverage using the restored effective owner.
- [x] 1.7 Add or confirm grouping coverage where rows with different mapped owners split the same source invoice.

## 2. Ownership Resolution

- [x] 2.1 Restore the full tag mapping in `Modules/Purchase/Services/PurchaseImportService.php`.
- [x] 2.2 Confirm `resolveEffectiveOwnerKey()` includes restored mapped tags.
- [x] 2.3 Confirm `resolveTenant()` resolves restored mapped tags.
- [x] 2.4 Confirm blank and unmapped tags keep PERDANA fallback behavior.
- [x] 2.5 Confirm document, ProductPrice, stock, location, and Transaction owner behavior remains aligned to the resolved effective owner.

## 3. Verification

- [x] 3.1 Run focused purchase import ownership tests.
- [x] 3.2 Run focused purchase import payment/allocation tests if grouping behavior changes are covered there.
- [x] 3.3 Run broader import-related regression tests if focused failures suggest shared behavior impact.
