## Why

## Why

The Global Sales Search page currently only searches 4 fields (serial number, sale reference, customer name, product name) on the `Sale` model. Users need to search across both standard Sales **and** POS Transactions (including drafts/loads that haven't generated sales yet) by any relevant keyword — barcode, POS transaction code, receipt number, seller name, cashier name, etc. Furthermore, the search should return a unified list of both Sales and POS Transactions, routing to the correct detail view depending on the record type. Clicking "view" on a cross-tenant sale or POS transaction should bypass regular isolation controls so authorized users don't encounter 404s. Debug logging in the search service also heavily impacts performance and must be removed.

## What Changes

- Expand `SerialNumberSearchService` to execute searches across **both** `Sale` and `PosTransaction` models, combining the results into a unified paginated list.
- Expand search OR conditions to include: product barcode, seller/user name, POS transaction code, POS receipt number, POS cashier name, and denormalized `customer_name`.
- Add `Sale::posCheckout()` HasOne relationship to enable POS-related search joins.
- Bypass tenant ownership validations (`ensureSaleBelongsToCurrentSetting()`) for users with `globalSalesSearch.access` permission (Option C) for both Sales and POS details.
- Update Livewire search results table to handle polymorphic rows: clicking a `Sale` routes to `/sales/{id}`, while clicking a `PosTransaction` routes to `/pos/transactions/{id}`.
- Remove performance-killing debug logging (`$query->get()` on every search) and excessive `Log::info()` calls.
- Remove dead/broken `detail.blade.php` view.
- Add total amount and "Type" (Sale vs POS) column to search results.

## Capabilities

### New Capabilities
- `global-sales-universal-search`: Unified keyword search across all fields (barcode, POS code, receipt number, seller, cashier) returning mixed collections of Sales and POS Transactions.
- `global-sales-cross-tenant-view`: Bypass tenant ownership check for users with global search permission to view any sale or POS transaction detail.

### Modified Capabilities
_(none — no existing spec-level behavior is changing)_

## Impact

- **Models**: `Modules/Sale/Entities/Sale.php` — add `posCheckout()` HasOne relationship
- **Services**: `Modules/Sale/Services/SerialNumberSearchService.php` — expand `buildQuery()` OR conditions, remove debug logging
- **Controllers**: `Modules/Sale/Http/Controllers/SaleController.php` — modify `ensureSaleBelongsToCurrentSetting()` to bypass for global search permission
- **Views**: `Modules/Sale/Resources/views/livewire/global-sales-search.blade.php` — add View link and total amount column
- **Cleanup**: Remove `Modules/Sale/Resources/views/global-sales-search/detail.blade.php` (dead code)
- **Livewire**: `Modules/Sale/Http/Livewire/GlobalSalesSearch.php` — clean up excessive debug logging
