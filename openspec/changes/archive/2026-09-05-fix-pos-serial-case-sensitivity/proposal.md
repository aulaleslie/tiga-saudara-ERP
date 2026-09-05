## Why

Product serial numbers are compared byte-exact (PHP `Collection::keyBy()`/`get()`, `in_array(..., true)`, `array_search(..., true)`, `array_diff()`, `array_intersect()`) at 12+ sites across POS, Sale, SalesReturn, PurchaseReturn, and Consignment, even though the `product_serial_numbers.serial_number` column's collation (`utf8mb4_unicode_ci`) is case-insensitive at the SQL layer. A serial scanned or typed in a different case than what is stored (e.g. lowercase vs. the canonical uppercase) passes the initial SQL fetch but then fails the in-memory PHP comparison, producing a false "not found" / "stock unavailable" rejection for a serial that is actually valid and in stock.

This is confirmed live in production: POS transactions `#2779` and `#3250` each have a cart-assigned serial stored in lowercase that cannot pass checkout, blocking payment for a physically available, unassigned unit. The `SaleController`/`SaleSerialDisplayResolver` code already independently solved this correctly (`mb_strtoupper(trim($value), 'UTF-8')` before comparing) — the same fix needs to be applied everywhere else and centralized so it cannot drift or be missed again.

## What Changes

- Add `ProductSerialNumber::normalize(string $serialNumber): string` (canonical `mb_strtoupper(trim($value), 'UTF-8')`) and a `setSerialNumberAttribute` mutator so all future writes to `product_serial_numbers` are stored pre-normalized.
- Normalize both sides of every serial-number comparison at the 12 confirmed case-sensitive sites (see design.md) in POS checkout (preflight, split planning, posting), POS cart (assignment, duplicate detection, removal), the scan-resolve endpoint, SalesReturn and PurchaseReturn Livewire form validation, and Consignment sold-source discovery / billing preview — so a comparison never depends on the caller's input casing.
- Replace the two existing ad-hoc `mb_strtoupper(trim())` implementations in `SaleController`/`SaleSerialDisplayResolver` with calls to the new shared `ProductSerialNumber::normalize()` helper, so there is one canonical implementation instead of three.
- No data migration: production data was verified 100% already uppercase with zero cross-product collisions: the fix is comparison-side only, so existing mismatched carts (`#2779`, `#3250`) resolve correctly the moment the fix ships, without any manual data correction.

## Capabilities

### New Capabilities

(none — this is a defect fix within existing serial-handling behavior, not a new capability)

### Modified Capabilities

- `pos-checkout-serial-stock-validation`: checkout preflight/finalize stock validation for serial-required lines must treat an assigned serial as fulfilled regardless of the case in which it was scanned, typed, or stored, as long as it matches an active, in-stock serial record case-insensitively.

## Impact

- **Code**: `Modules/Product/Entities/ProductSerialNumber.php` (new normalize helper + mutator); `Modules/Pos/Services/ResolvePosStockAllocationsService.php`, `PosCheckoutSplitPlannerService.php`, `Adapters/InlinePosCheckoutPostingAdapter.php`, `PosCartService.php`, `PosScanResolverService.php`; `app/Livewire/SalesReturn/Concerns/ValidatesSaleReturnForm.php`; `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php`; `Modules/Consignment/Services/ConsignmentSoldSourceDiscoveryService.php`, `ConsignmentBillingPreviewService.php`; `Modules/Sale/Http/Controllers/SaleController.php`; `Modules/Sale/Services/SaleSerialDisplayResolver.php`.
- **Data**: none required — verified no backfill or collision-handling needed.
- **Users**: cashiers/staff scanning or typing serial numbers in POS, Sales, Sales Returns, Purchase Returns, and Consignment workflows will no longer see false "not found" / "stock unavailable" errors caused by case mismatches; two currently-stuck POS carts (`#2779`, `#3250`) become checkout-able without intervention.
- **Risk**: comparison-only change with no schema/data changes; several affected sites use `lockForUpdate()` — fix must only change post-fetch keying/lookup, not query structure or lock scope.
