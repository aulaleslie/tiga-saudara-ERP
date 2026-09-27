## Context

Currently, the `sales.dispatch` permission allows users to dispatch items via the `sales.storeDispatch` and `sales.dispatch` endpoints. However, they lack a navigational entry point because the parent "Penjualan" sidebar menu and the main list "Daftar Penjualan" are guarded strictly by `sales.access` and `saleReturns.access`. As a result, warehouse or fulfillment staff with only dispatch roles are invisible to the relevant UI.

This design introduces a decoupled architecture for the dispatch list—paralleling a previously implemented fix for the Purchase module's receiving workflow—enabling staff to see only the items they can action (`APPROVED` and `DISPATCHED_PARTIALLY`) on a dedicated page without being granted global sales access.

## Goals / Non-Goals

**Goals:**
- Provide a dedicated, filterable list UI for sales dispatches.
- Display "Penjualan" main sidebar menu if user has `sales.dispatch`.
- Display a new "Pengiriman Barang" sidebar item under "Penjualan".
- Guard the new view correctly with the `sales.dispatch` permission.

**Non-Goals:**
- Modifying the existing dispatch process, forms, or logic.
- Splitting the `sales.dispatch` permission out of the `Penjualan` group in `Permissions.php` (it remains naturally grouped under sales).
- Handling returns or other sales states outside dispatch.

## Decisions

1. **A New Controller Method vs Reusing `index`**
   - We will implement `dispatchIndex` within `Modules/Sale/Http/Controllers/SaleController.php` rather than overloading the existing `index` method or creating a standalone controller. 
   - *Rationale*: Reusing `index` requires overly complex front-end logic to determine what data the user is allowed to see and hides the intent. A new method perfectly scopes the data to actionable dispatch rows, keeping the code clean, and mirroring `PurchaseController@receivingIndex`.

2. **Data Table Approach**
   - We will create a new Livewire component or just a Blade view specifically for dispatching (e.g. `Modules/Sale/Resources/views/dispatch/index.blade.php`), returning a filtered collection of `Sale::whereIn('status', ['APPROVED', 'DISPATCHED_PARTIALLY'])`.
   - *Rationale*: Creating a quick filtered view prevents dispatch staff from seeing draft sales, rejected sales, or fully dispatched sales by default, streamlining their workflow.

3. **Menu Guard Changes**
   - The `menu.blade.php` guard for the `Penjualan` dropdown will be updated to `@canany(['sales.access', 'saleReturns.access', 'sales.dispatch'])`. 
   - A new submenu under `@can('sales.dispatch')` will be added pointing to `route('sales.dispatches.index')`.
   - *Rationale*: Correctly couples visual availability with backend authorization.

## Risks / Trade-offs

- **Risk: Pagination and Data Table Overhead** → Depending on the number of approved sales, a standard Blade view with `->get()` might be slow. 
  *Mitigation*: We will paginate the results or use Datatables (e.g., `SalesDispatchDataTable`) if necessary, but a standard heavily-filtered paginated query is preferred for simplicity if volume is manageable. Let's aim to use the `datatable` approach if standard `SalesDataTable` can be adapted, otherwise a simple paginated query in a new view. Given `PurchaseController@receivingIndex` used a custom view, we will use a custom view `dispatch/filtered-index.blade.php`.
