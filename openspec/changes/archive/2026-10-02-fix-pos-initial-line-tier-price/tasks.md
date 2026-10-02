# Tasks

## 1. Tests first

- [x] 1.1 In `POSCustomerTierRepricingTest`, add customer-first tests for WHOLESALER and RESELLER on an ordinary product (unit price and tier-priced source); verify they fail before the fix by running `php artisan test --filter=POSCustomerTierRepricingTest`
- [x] 1.2 Add tests for tier price zero fallback, no-tier customer, conversion unit on an ordinary product (tier price per base unit), repeated add merging into one line, and bundle unaffected; verify with the same filtered run

## 2. Implementation

- [x] 2.1 Apply the tier price in the ordinary branch of the add-line flow in `Modules/Pos/Services/PosCartService.php`, keeping the existing product-price fallback, and tag the line as tier-priced when applied; verify the new tests pass
- [x] 2.2 Verify packed and bundle behavior is untouched by running only `--filter=POSCustomerTierRepricingTest` and `--filter=PosTierBypassConversionPricingTest` (focused; no full suite)
