# Sales Dispatch Related Files

URL: `/sales/{sale}/dispatch`

## Routes
- `Modules/Sale/Routes/web.php`

## Controller
- `Modules/Sale/Http/Controllers/SaleController.php`
  - `dispatch()` (GET)
  - `storeDispatch()` (POST)

## Views
- `Modules/Sale/Resources/views/dispatch.blade.php`
- `resources/views/livewire/sale/dispatch-sale-header.blade.php`
- `resources/views/livewire/sale/dispatch-sale-table.blade.php`

## Livewire Components
- `app/Livewire/Sale/DispatchSaleHeader.php`
- `app/Livewire/Sale/DispatchSaleTable.php`

## Serial Validation Endpoint
- `Modules/Product/Routes/web.php`
- `Modules/Product/Http/Controllers/SerialNumberController.php`
  - `validateDispatchSerial()`

## Entry Points (links to dispatch page)
- `Modules/Sale/Resources/views/show.blade.php`
- `Modules/Sale/Resources/views/partials/actions.blade.php`

## Models/Entities Used by Dispatch Flow
- `Modules/Sale/Entities/Sale.php`
- `Modules/Sale/Entities/SaleDetails.php`
- `Modules/Sale/Entities/SaleBundleItem.php`
- `Modules/Sale/Entities/Dispatch.php`
- `Modules/Sale/Entities/DispatchDetail.php`
- `Modules/Product/Entities/Product.php`
- `Modules/Product/Entities/ProductStock.php`
- `Modules/Product/Entities/ProductSerialNumber.php`
- `Modules/Setting/Entities/Location.php`
- `Modules/Setting/Entities/Tax.php`
