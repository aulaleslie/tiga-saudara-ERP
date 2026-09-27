## Why

Currently, the `sales.dispatch` permission only grants the ability to use the "Pengeluaran Barang" action button within the sales data table. However, because the overarching "Penjualan" sidebar menu and "Daftar Penjualan" list both require `sales.access`, users who are only granted dispatch permissions are completely locked out of the interface. This mirrors a parallel issue previously solved in the Purchase module (where receiving was strictly tied to purchase access). There needs to be a dedicated entry point for dispatch-only roles to view and manage approved sales ready for shipment.

## What Changes

- Add a dedicated "Pengiriman Barang" (Dispatch) menu item under the "Penjualan" sidebar group.
- Update the sidebar "Penjualan" group guard to also allow users with `sales.dispatch`.
- Create a dedicated dispatch index page (`sales.dispatch.index` or `sales.dispatch.list`) accessible via the new menu.
- The new dispatch list will only display sales that have a status of `APPROVED` or `DISPATCHED_PARTIALLY` (waiting for dispatch items), ensuring warehouse staff only see actionable items without needing full `sales.access` across all draft/completed sales.
- **BREAKING**: No breaking changes; this is strictly additive for improved permission decoupling.

## Capabilities

### New Capabilities
- `sales-dispatch-menu-isolation`: Isolating the sales dispatch list and entry points from general sales access control, providing a dedicated interface for warehouse/dispatch operations.

### Modified Capabilities


## Impact

- **Code/Views**: `menu.blade.php` will be updated to include the new dropdown item and expand the parent guard. A new Blade view for the dispatch index will be introduced.
- **Controllers**: A method in `SaleController` (e.g., `dispatchIndex`) will return the filtered index view.
- **Roles/Permissions**: Dispatch-only personnel will finally be able to perform their job without needing overly broad `sales.access`.
