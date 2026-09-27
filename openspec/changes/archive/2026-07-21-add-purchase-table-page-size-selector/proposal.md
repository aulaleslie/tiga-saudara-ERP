## Why

Currently, Livewire data tables in the project (such as `PurchaseTable`) hardcode the number of records displayed per page (e.g., `10`). While Yajra DataTables in the project automatically provide a dropdown to select the number of records, the custom Livewire tables lack this basic usability feature. Adding a page size selector allows users to view more data at once when needed, reducing clicks and improving the overall user experience, particularly on screens like the Global Payments list.

## What Changes

- Add a UI dropdown selector (10, 25, 50, 100) to control the `perPage` property in the `PurchaseTable` Livewire component.
- The UI will be placed in the pagination footer next to the "Menampilkan … data" count at the bottom of the table.
- Introduce a lifecycle hook in the Livewire component to automatically reset the current page to 1 whenever the page size is changed, preventing out-of-bounds pagination errors.
- Establish a standard pattern for implementing this feature in other Livewire components in the future.

## Capabilities

### New Capabilities
- `purchase-table-pagination`: Controls the number of items displayed per page on the Purchase table, ensuring safe pagination state resets.

### Modified Capabilities
- (None)

## Impact

- **Livewire Components**: `App\Livewire\Purchase\PurchaseTable`
- **Views**: `resources/views/livewire/purchase/purchase-table.blade.php`
- This change will affect all pages utilizing the `PurchaseTable` component, including the standard Purchase index and the Global Payments view.
