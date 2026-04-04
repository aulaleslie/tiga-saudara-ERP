## 1. Shared pending-dispatch guard helper

- [x] 1.1 Create `PendingDispatchSerialGuard` helper class with `isReserved(string $serialNumber): bool` that checks if a serial is in any PENDING dispatch's `DispatchDetail.serial_numbers` JSON
- [x] 1.2 Add `getReservedSerialsForProduct(int $productId): array` method that returns all serial numbers in PENDING dispatches for a given product (batch filter for search)

## 2. POS serial search guard

- [x] 2.1 In `PosCartService::availableSerialsForProduct()`, use `getReservedSerialsForProduct()` to exclude PENDING-dispatch serials from autcomplete results via `whereNotIn`

## 3. POS serial append guard

- [x] 3.1 In `PosCartService::appendSerial()`, after existing status/dispatch_detail_id checks, call `PendingDispatchSerialGuard::isReserved()` and throw `DomainException` with message "Serial number {serial} sedang dalam proses pengiriman."

## 4. Finalize pre-check guard

- [x] 4.1 In the finalize/checkout pre-check serial validation, add a pending-dispatch check for each assigned serial and fail with `STOCK_UNAVAILABLE` if any assigned serial is in a PENDING dispatch

## 5. Tests

- [x] 5.1 Test: `availableSerialsForProduct` excludes serials in PENDING dispatches
- [x] 5.2 Test: `availableSerialsForProduct` includes serials from REJECTED dispatches
- [x] 5.3 Test: `appendSerial` rejects serial in PENDING dispatch with correct error message
- [x] 5.4 Test: `appendSerial` succeeds for serial not in any PENDING dispatch
- [x] 5.5 Test: finalize pre-check rejects cart with assigned serial that entered PENDING dispatch
