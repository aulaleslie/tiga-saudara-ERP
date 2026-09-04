## Why

POS product search and the Price Points ("Terminal Harga") browser both display the exact remaining stock quantity (e.g. "Stok 12 PCS") to every user who can reach those screens. Not every role needs to know precise on-hand quantities — only whether a product is currently sellable. Exposing exact counts to all cashiers/staff is more information than their role requires.

## What Changes

- Add a single new permission, `inventory.view_remaining_stock`, governing whether the numeric stock-quantity element is shown.
- In POS product search (`PosProductSearchService` / `sell.blade.php` "Cari Produk" cards) and in Price Points (`Browser.php` / `browser.blade.php`), the entire "Stok N <unit>" element is omitted from the rendered card for users without the permission — not blanked, not replaced with a placeholder, simply absent.
- The numeric `available_qty` (and its formatted/denominated string) is only attached to the response/component data for users who hold the permission; for users without it, the field is omitted from the payload rather than merely hidden by CSS/blade, so the exact quantity does not reach the client at all.
- No change to: in-stock/out-of-stock/service-badge determination, `available_qty <= 0` disabled-card logic, "Stok Kosong" / "Service" badges, card selectability, search/filter/pagination behavior, or unit-conversion formatting logic itself (only its visibility).

## Capabilities

### New Capabilities
- `inventory-remaining-stock-visibility`: Defines the `inventory.view_remaining_stock` permission and the rule that the numeric stock-quantity element is included in POS and Price Points product cards only for users holding it.

### Modified Capabilities
- `pos-product-search-stock-visibility`: Search results continue to include out-of-stock matches and disabled-card treatment for everyone; the numeric `available_qty` field on each result is now conditional on the new permission.
- `price-point-stock-visibility`: Stock-state badges (in-stock/out-of-stock/service) and denominated-quantity *formatting* logic are unchanged; whether the formatted quantity is rendered at all is now conditional on the new permission.

## Impact

- `Modules/Pos/Services/PosProductSearchService.php` — conditionally omit `available_qty` from each result row based on the acting user's permission.
- `Modules/Pos/Resources/views/sell.blade.php` — stop rendering the "Stok" line when the field is absent from the product payload.
- `app/Livewire/PricePoint/Browser.php` — conditionally omit `available_qty` / `formatted_available_qty` from product data passed to the view.
- `resources/views/livewire/price-point/browser.blade.php` — stop rendering the "Stok" line when the field is absent.
- `app/Config/Permissions.php` — register `inventory.view_remaining_stock` (new permission entry, likely under a shared/inventory grouping since it applies to both POS and Price Points).
- Role/permission seeders and existing role assignments are unaffected structurally, but an admin will need to grant the new permission to roles that should retain quantity visibility (out of scope for this change to decide — a data/seeding concern, not a spec concern, unless the user wants a default grant documented).
