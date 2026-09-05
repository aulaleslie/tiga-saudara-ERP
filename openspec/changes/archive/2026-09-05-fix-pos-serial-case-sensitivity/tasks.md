## 1. Shared normalization helper

- [x] 1.1 Add `ProductSerialNumber::normalize(string $serialNumber): string` to `Modules/Product/Entities/ProductSerialNumber.php`, implemented as `mb_strtoupper(trim($serialNumber), 'UTF-8')` — copied verbatim from the existing proven implementation in `SaleSerialDisplayResolver::normalizeSerialValue()`.
- [x] 1.2 Add a `setSerialNumberAttribute` mutator on the same model that stores `self::normalize($value)`, so every future create/update is normalized at the write boundary.

## 2. POS checkout: preflight and stock allocation

- [x] 2.1 In `Modules/Pos/Services/ResolvePosStockAllocationsService.php` (`allocateSerialLineUsingAssignedSerials`), change `->keyBy('serial_number')` to key by `ProductSerialNumber::normalize($row->serial_number)`, and normalize the lookup key passed to `$serialRows->get(...)`.
- [x] 2.2 In the same method, use the matched record's canonical `serial_number` (not the raw input) for the `PendingDispatchSerialGuard::isReserved(...)` call and for the value pushed into the allocation's `serial_numbers` array, so canonical values propagate downstream.

## 3. POS checkout: split planning and posting

- [x] 3.1 In `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`, apply the same `keyBy`/lookup normalization as 2.1, and propagate the matched record's canonical serial into the chunk's `serial_numbers` output.
- [x] 3.2 In `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, apply the same normalization to both `keyBy('serial_number')` sites (parent-line path and bundle-component path) and their corresponding `isset(...)`/array-access lookups.
- [x] 3.3 In the same file, fix the `array_intersect($compAssignedSerials, $chunkSerials)` bundle-component filter to compare normalized values on both sides instead of raw strings.
- [x] 3.4 Verify (by reading, not by restructuring) that the `lockForUpdate()` queries in this file are unchanged — only the post-fetch `keyBy`/lookup keying should differ, not the query itself.

## 4. POS cart: assignment, duplicate detection, removal

- [x] 4.1 In `Modules/Pos/Services/PosCartService.php::assignSerialsWithinLock()`, normalize each incoming serial number immediately (both the parent-line branch and the bundle-component branch) before validating against `product_serial_numbers` and before storing into `assigned_serials` / `bundle_item_serials`.
- [x] 4.2 In `collectCartWideAssignedSerials()`, normalize the returned list so pre-existing (possibly legacy-cased) cart entries still compare correctly against newly normalized input.
- [x] 4.3 Fix the `in_array(..., true)` duplicate-assignment checks and the `array_search(..., true)` removal lookups in `PosCartService.php` to compare normalized values.

## 5. POS scan resolver

- [x] 5.1 In `Modules/Pos/Services/PosScanResolverService.php::resolve()`, normalize the scanned/typed query string before the exact serial-number match.

## 6. SalesReturn and PurchaseReturn form validation

- [x] 6.1 In `app/Livewire/SalesReturn/Concerns/ValidatesSaleReturnForm.php`, normalize submitted serial numbers before `whereIn('serial_number', ...)` / `keyBy('serial_number')` / `get(...)`.
- [x] 6.2 In `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php`, normalize submitted serial numbers before the `whereIn('serial_number', ...)` query and before the `array_diff($serialNumbers, $existing)` missing-serial check.

## 7. Consignment

- [x] 7.1 In `Modules/Consignment/Services/ConsignmentSoldSourceDiscoveryService.php`, normalize the `keyBy(fn ($psn) => (string) $psn->serial_number)` closure and the `$rawSerials` values compared via `array_diff` against it.
- [x] 7.2 In `Modules/Consignment/Services/ConsignmentBillingPreviewService.php`, normalize both sides of the `in_array($allocSnStr, $soldSideSerialStrings, true)` comparison.

## 8. Centralize existing correct implementations

- [x] 8.1 Replace `SaleController::saleSerialLookupKey()`'s private normalization with a call to `ProductSerialNumber::normalize()`.
- [x] 8.2 Replace `SaleSerialDisplayResolver::normalizeSerialValue()`'s body with a call to `ProductSerialNumber::normalize()`.

## 9. Focused verification (no full suite)

- [x] 9.1 Add/extend a unit test for `ProductSerialNumber::normalize()` covering mixed case, leading/trailing whitespace, and confirming output matches what `SaleController`/`SaleSerialDisplayResolver` previously produced for the same inputs.
- [x] 9.2 Run the existing targeted POS serial test files only (e.g. `POSSerialValidationCheckoutTest`, `POSStockAllocationResolverTest`, `PosCheckoutSplitPlannerServiceTest`, `POSScanResolveEndpointTest`) via `php artisan test --filter=<TestName>` and confirm they still pass unmodified.
- [x] 9.3 Add one new focused regression test reproducing the production scenario: a cart line with a lowercase-assigned serial against an uppercase-stored `product_serial_numbers` record passes checkout preflight and finalize.
- [x] 9.4 Run the targeted SalesReturn, PurchaseReturn, and Consignment test files touched by tasks 6-7 (existing filenames only, via `--filter`) to confirm no regression — do not run the full suite.
- [x] 9.5 Leave end-to-end/browser verification of POS transactions `#2779` and `#3250` completing checkout to human eye/browser test, as instructed — no automated E2E task needed here.

## 10. Append-serial gap (found in review)

- [x] 10.1 In `Modules/Pos/Services/PosCartService.php::appendSerialWithinLock()`, normalize `$serialNumber` immediately at the top of both the bundle-component branch and the parent-line branch via `ProductSerialNumber::normalize($serialNumber)`.
- [x] 10.2 Use canonical serial from matched DB record (`(string) $record->serial_number`) for `PendingDispatchSerialGuard::isReserved()` in both branches of `appendSerialWithinLock()`.
- [x] 10.3 Normalize `$assignedSerials` before `in_array($serialNumber, $normalizedAssignedSerials, true)` in both branches of `appendSerialWithinLock()`, and append the normalized `$serialNumber`.
- [x] 10.4 In `Modules/Pos/Services/PosCartService.php::appendSerialByLookupWithinLock()`, normalize `$serialNumber` at the top, normalize locally-built `$allAssignedSerials`, use canonical serial in `PendingDispatchSerialGuard::isReserved()`, and pass normalized `$serialNumber` to `appendSerialWithinLock()`.
- [x] 10.5 In `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`, normalize the duplicate-detection checks at lines 224 and 446 by comparing `array_unique` against a normalized copy of the serials array.
- [x] 10.6 Run `php artisan test --filter=POSBundleCartManagementTest` to confirm existing append routes pass unmodified.
- [x] 10.7 Add new regression test `test_case_insensitive_serial_checkout_with_lowercase_append_route_succeeds` in `Modules/Pos/Tests/Feature/POSSerialValidationCheckoutTest.php` exercising lowercase serial appending via `pos.sell.cart.lines.serials.append` through to POSTED sale, SOLD serial, and canonical uppercase serial in `DispatchDetail`.

## 11. Sale dispatch validation gap (found in review)

- [x] 11.1 In `Modules/Sale/Http/Controllers/SaleController.php` (around line 725), update `PendingDispatchSerialGuard::isReserved($serialNumber)` to pass the canonical value from the matched record `(string) $snRecord->serial_number`.
- [x] 11.2 Run existing tests covering `storeDispatch` serial validation (`SalesDispatchBundleComponentSerialRegressionTest` and `DispatchApprovalTest`) and confirm they pass unmodified.
