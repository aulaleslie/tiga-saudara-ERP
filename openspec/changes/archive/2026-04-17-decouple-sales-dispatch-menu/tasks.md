## 1. Routing & Controllers

- [x] 1.1 Add `sales.dispatches.index` route (GET `/sales/dispatches`) in `Modules/Sale/Routes/web.php` guarded by `sales.dispatch` middleware/can.
- [x] 1.2 Implement `dispatchIndex` method in `Modules/Sale/Http/Controllers/SaleController.php` to fetch or render the dispatch list relying on `sales.dispatch` permission.

## 2. Views & Interface

- [x] 2.1 Create `Modules/Sale/Resources/views/dispatch/filtered-index.blade.php` to display sales ready for dispatch (status `APPROVED` or `DISPATCHED_PARTIALLY`).
- [x] 2.2 Update `resources/views/layouts/menu.blade.php` to modify the "Penjualan" parent group guard to `@canany(['sales.access', 'saleReturns.access', 'sales.dispatch'])`.
- [x] 2.3 Add a new "Pengiriman Barang" (Dispatch) sub-menu item in `menu.blade.php` under the "Penjualan" group, guarded by `@can('sales.dispatch')` and linking to `sales.dispatches.index`.
