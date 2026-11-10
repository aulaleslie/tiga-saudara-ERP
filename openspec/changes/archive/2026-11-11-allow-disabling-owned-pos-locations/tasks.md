# Tasks

## 1. Persistent lifecycle choices

- [x] 1.1 Change `SettingSaleLocation::repairMissingOwnedAssignments` to create only absent assignments; verify focused tests prove disabled rows survive repeated repair, existing enabled rows retain priority, and missing rows are enabled once at the end.
- [x] 1.2 Change `Location` ownership-transfer handling to create an enabled assignment only when absent; verify tests for existing disabled/enabled assignments, missing assignment append order, unchanged former-owner choices, and cache refresh for both businesses. Retain standard and quick-add owner-only creation defaults and verify their existing focused coverage.

## 2. Configuration controls and ordering

- [x] 2.1 Remove the owned-disable controller restriction and include disabled owned locations in the available query with correct ownership metadata; replace the blocked-disable test with successful disable/reload/re-enable coverage and verify other businesses' rows and global location status remain unchanged.
- [x] 2.2 Show owned-location disable/enable controls and replace misleading always-enabled copy in the configuration Blade view; verify rendered actions, available-list membership, ownership badges, and edit-permission denial with focused feature assertions.
- [x] 2.3 Verify owned disable clears priority, re-enable appends, disabled rows cannot be reordered, and warm resolver caches refresh on both transitions; extend existing configuration tests only where these cases are missing.

## 3. Shared eligibility and empty configuration

- [x] 3.1 Add focused POS coverage for disabling the last enabled location, including a cart built before disablement and stock/non-stock checkout attempts; verify no owner fallback, no sale or stock posting, and a clear missing-enabled-location message. Make a targeted validation/message adjustment if current stock-unavailable handling does not meet that requirement.
- [x] 3.2 Verify enabling a location after empty configuration restores resolver eligibility immediately; reuse search/checkout regression fixtures and confirm disabled owned stock is excluded while another enabled location remains usable.
- [x] 3.3 Add or extend focused Price Point and Sales average-cost resolver cases to prove consumers retain their existing shared configuration rules after owned disablement; verify historical transaction records are unchanged and avoid expanding inventory or transfer policy scope.

## 4. Focused integration verification

- [x] 4.1 Run `php artisan test --filter=SaleLocationConfigurationTest` and the specific POS, Price Point, and AverageCostResolver tests touched above using the repository's isolated SQLite test setup where needed; record exact commands and results. Broaden testing only if failures or additional changes warrant it.
- [x] 4.2 Check the complete diff for schema/data migration absence, retained enabled defaults, and changes limited to the agreed behavior; run `openspec validate allow-disabling-owned-pos-locations --strict` and record the result.

## Verification Log

- `php artisan test --filter=SaleLocationConfigurationTest` → 21 passed (103 assertions)
- `php artisan test --filter=POSCheckoutPreflightTest` → 9 passed (63 assertions)
- `php artisan test --filter='AverageCostResolverTest|PricePointBrowserSearchTest'` → 17 passed (59 assertions)
- Broadened run (new checkout guard touches shared POS path): `php artisan test Modules/Pos/Tests Modules/Setting/Tests tests/Feature/MasterDataDeactivationTest.php Modules/Consignment/Tests/Feature/ConsignmentPosCheckoutDiscoveryTest.php Modules/Product/Tests/Feature/ProductBarcodePosRegressionTest.php Modules/Sale/Tests/Feature/StandardSaleLocationScopeRegressionTest.php` → 77 failed / 1793 passed; identical failing set on a pre-change baseline (77 failed / 1789 passed), so no regressions introduced.
- Diff contains no schema or data migrations; Location creation and missing-assignment repair still default to enabled.
- `openspec validate allow-disabling-owned-pos-locations --strict` → valid
