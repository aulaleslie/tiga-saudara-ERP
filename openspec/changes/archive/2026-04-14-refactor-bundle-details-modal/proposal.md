## Why

The current product cart UI displays bundle details via an inline collapsible row underneath each bundle product. This approach disrupts the table layout, pushing subsequent products down, and feels clunky, especially on mobile devices or when dealing with multiple bundle items. Moving this to a Livewire-driven modal will keep the UI clean, maintain consistent table height, and provide a more focused and premium user experience when examining bundle contents.

## What Changes

- Refactor `resources/views/livewire/sale/product-cart.blade.php` to remove the inline `tr.collapse` for bundle details.
- Add a new "Lihat Paket Penjualan" button (with modern styling, e.g. `btn-info text-white btn-sm`) on the product row that triggers a Livewire method instead of a DOM collapse.
- Add public properties to `App\Livewire\Sale\ProductCart` to store the selected bundle's items, name, and string for modal display.
- Add a new method `viewBundleDetails($rowId)` inside `App\Livewire\Sale\ProductCart.php` to retrieve bundle options from the cart and dispatch a browser event to open the modal.
- Create a new Blade partial (e.g. `resources/views/livewire/sale/includes/bundle-details-modal.blade.php`) containing a responsive table layout for bundle items, ensuring mobile compatibility.

## Capabilities

### New Capabilities

*(None)*

### Modified Capabilities

- `product-search`: Moving bundle detail presentation from inline to a modal in the POS/Sale cart context without changing the underlying business logic. (No strict spec change required since this is pure presentation refactoring).

## Impact

- `resources/views/livewire/sale/product-cart.blade.php`
- `App\Livewire\Sale\ProductCart.php`
- New view file: `resources/views/livewire/sale/includes/bundle-details-modal.blade.php`
