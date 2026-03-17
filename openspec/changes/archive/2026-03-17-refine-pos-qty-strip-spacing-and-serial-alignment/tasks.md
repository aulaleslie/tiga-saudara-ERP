## 1. Normalize Qty Control Markup

- [x] 1.1 Refactor `buildLineRow()` qty-cell templates so all row variants use one compact `[-/slot][input][+]` composition contract.
- [x] 1.2 Remove branch-specific inline `gap` and width drift in qty-strip containers, replacing with shared class usage.
- [x] 1.3 Ensure non-serial and serial top spinner rows render in the same control order for privileged and non-privileged users.

## 2. Apply Spinner Styling Contract

- [x] 2.1 Update idle decrease controls (`js-qty-decrease` and `js-reduce-qty` default state) to danger-outline styling with fill-on-hover behavior.
- [x] 2.2 Update increase controls (`js-qty-increase`) to primary-outline styling with fill-on-hover behavior.
- [x] 2.3 Preserve current button shape conventions (rectangle with existing corner radius and compact size).

## 3. Stack And Center Serial Qty Cell Content

- [x] 3.1 Recompose serial qty-cell structure into centered stacked rows: spinner row, serial-action row, serial-chip row.
- [x] 3.2 Keep serial chips wrapping behavior while ensuring chips remain centered beneath the serial-action row.
- [x] 3.3 Keep serial assignment counter readable and aligned within the stacked serial qty-cell layout.

## 4. Preserve Approval And Permission Behavior

- [x] 4.1 Verify non-privileged approval slot states (`Reduce`, `Periksa`, approved proceed) still render and transition correctly after compact layout changes.
- [x] 4.2 Verify privileged decrease/increase paths remain direct and unaffected by styling/markup refactor.
- [x] 4.3 Confirm serial-action button remains functional and opens modal with correct active line context.

## 5. Regression Validation

- [x] 5.1 Add or update tests/assertions for compact qty control rendering across serial/non-serial and permission states.
- [x] 5.2 Add or update tests/assertions for centered stacked serial qty-cell layout expectations.
- [x] 5.3 Execute targeted POS supervised-cart and serial-row regression checks, then document manual UI validation results.
