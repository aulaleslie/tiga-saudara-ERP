## 1. Database & Model Links

- [x] 1.1 Add `posCheckout()` HasOne relation to `Modules\Sale\Entities\Sale` pointing to `Modules\Pos\Entities\PosCheckout` using `sale_id`.

## 2. Search Query and Merging Logic

- [x] 2.1 Refactor `SerialNumberSearchService::buildQuery` into two separate Builder generators: one for `Sale` and one for `PosTransaction`, implementing the advanced OR filtering logic (product barcode, seller, cashier, receipt number, code, denormalized customer name) on both entities.
- [x] 2.2 Inside `SerialNumberSearchService`, delete the performance-killing `$query->get()` logging block.
- [x] 2.3 Create a new method `SerialNumberSearchService::getUnifiedPagination` that executes both queries configured with chunk limits, maps the resulting models into generic array/DTO structures containing standardized keys (`id`, `type` -> `sale|pos`, `reference`, `customer`, `date`, `total`, `status`), merges them, sorts by date descending, and returns a `LengthAwarePaginator`.
- [x] 2.4 Update `Modules/Sale/Http/Livewire/GlobalSalesSearch.php` to invoke `getUnifiedPagination` and bind the resulting mapped array to the component state.

## 3. Detail View Routing Bypass

- [x] 3.1 Update `SaleController::ensureSaleBelongsToCurrentSetting()` to execute `if (Gate::allows('globalSalesSearch.access')) { return; }` to support cross-tenant views.
- [x] 3.2 Update POS transaction controllers (or wherever POS authorization occurs) to implement the same bypass when viewing `/pos/transactions/{id}`.

## 4. UI Cleanups and Enhancements

- [x] 4.1 Delete the broken/dead file: `Modules/Sale/Resources/views/global-sales-search/detail.blade.php`.
- [x] 4.2 In `Modules/Sale/Resources/views/livewire/global-sales-search.blade.php`, update the table to read the unified data structure (rendering type badges, standard references, and "Total Amount").
- [x] 4.3 In `livewire/global-sales-search.blade.php` and its parent controller `index.blade.php`, update the `viewSale` click logic. Emit an event carrying the `type` so the JS can perform either `window.open('/sales/' + id)` or `window.open('/pos/transactions/' + id)`.
