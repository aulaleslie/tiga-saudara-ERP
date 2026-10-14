# Tasks

## 1. Stable conversion identity

- [x] 1.1 Add focused regression cases in ProductUnitConfigurationComponentTest for integer saved IDs, string old-input IDs, null/empty new-row IDs, and first/middle/last removal with string button arguments; verify the integer-ID case fails on the current implementation and expectations cover exactly one removed row and preserved surviving values.
- [x] 1.2 Normalize initial and preserved row keys to stable nonempty strings in UnitConfiguration while retaining strict lookup and separate persisted IDs; verify the targeted removal cases pass and repeated add/remove operations keep unique surviving identities and values.

## 2. Surviving dropdown and payload alignment

- [x] 2.1 Add focused nested-component coverage for three distinct conversion rows, changing a surviving dropdown's unit and removing an earlier row; verify the test inspects actual child hidden-input names and selected values rather than only parent array indexes.
- [x] 2.2 Make UnitSearchDropdown's name reactive while retaining stable per-row component keys and child selection state; verify surviving hidden unit fields follow compacted parent indexes and the focused nested/dropdown tests pass.
- [x] 2.3 Add a focused validation-round-trip regression for create/edit conversion payloads after removal; verify the removed conversion stays absent and surviving IDs, units, factors, barcodes, canonical prices, and explicit false enablement flags are restored under matching indexes.

## 3. Focused verification

- [x] 3.1 Run `php artisan test --filter=ProductUnitConfigurationComponentTest`, `php artisan test --filter=UnitSearchDropdownTest`, and only the targeted validation regression filter introduced in task 2.3; record results and any environment limitations. No full-suite or unfiltered fresh-SQLite run is required.
- [x] 3.2 Perform a targeted browser check on both create and edit: populate three distinct conversion rows, trigger unrelated server validation, modify surviving values/unit selection, remove first or middle rows, add/remove again, and resubmit; verify DOM and request indexes agree, removed rows stay absent, surviving edits persist, and Hapus works on saved edit rows. Record evidence or a concrete browser-access blocker, and investigate any remaining failure using console/network evidence.

> **Verification Status & Blocker Record:**
> - **Focused Test Results:** 15 passed, 147 assertions (`ProductUnitConfigurationComponentTest`, `UnitSearchDropdownTest`, `ProductConversionValidationRoundTripTest`).
> - **Browser Blocker:** Targeted browser automation was blocked due to the absence of a running MySQL service required for the web application server in this local environment.
> - **Scope & Limitation:** The component and integration test suite exercises child unit selection, index compaction, child reactive prop hydration, and database persistence via controller form payload submission. Client-side browser FormData serialization and client DOM request bundling remain an environment limitation to be confirmed in a live browser test runner.

