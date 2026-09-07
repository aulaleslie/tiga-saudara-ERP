## Why

Selecting a product on `/adjustments/create` clears the search without adding a table row because the reused purchase search sends its event exclusively to the purchase cart. The same wiring affects adjustment edit and breakage create/edit, preventing users from adding products on these screens.

## What Changes

- Make the reused purchase search's selection recipient configurable, retaining the purchase cart as its default.
- Configure adjustment create/edit to receive selections in the adjustment table and breakage create/edit in the breakage table.
- Preserve selection payloads, location and duplicate guards, stock quantities, and serial handling.
- Add focused regression verification for selection delivery, all four page bindings, and unchanged default purchase routing; no full test suite is required.

## Capabilities

### New Capabilities

- `adjustment-product-selection`: Product selection delivery to the correct adjustment or breakage table, with purchase compatibility and existing selection guards.

### Modified Capabilities

None.

## Impact

- `app/Livewire/Purchase/SearchProduct.php` and the four adjustment/breakage create/edit Blade views.
- Focused Livewire/page regression tests under existing test conventions.
- No database migration, inventory correction, new dependency, or change to adjustment submission/posting behavior.
