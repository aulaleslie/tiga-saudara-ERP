## Why

When opening a "quick-add" dialog (e.g. Add Tax) from inside the Product Quick-Add modal on the purchases/create page, users cannot focus or type into any input fields. CoreUI's Bootstrap modal enforces a focus trap on the Product modal, and because the page-level Tax modal sits outside the Product modal's DOM, every focus attempt is yanked back. This makes nested entity creation (tax, category, brand, unit) impossible from within the Product modal.

## What Changes

- Introduce scoped Livewire event names so that quick-add modals nested inside the Product modal only open the **nested** instance (which lives inside the Product modal DOM and is allowed by the focus trap), while page-level triggers continue to open the **page-level** instance.
- The `tax-search-dropdown` (and analogous category/brand/unit search dropdowns) inside the Product modal will dispatch a scoped event (e.g. `openNestedTaxModal`) instead of the global `openTaxModal`.
- Nested quick-add modal instances inside `product-quick-add-modal.blade.php` will listen to the scoped event name.
- Page-level quick-add modals remain unchanged — they continue to listen to the existing global events.

## Capabilities

### New Capabilities
- `nested-modal-scoped-events`: Scoped Livewire event dispatching for quick-add modals rendered inside another modal, preventing dual-instance activation and focus trap conflicts.

### Modified Capabilities

## Impact

- **Blade views**: `product-quick-add-modal.blade.php`, `tax-search-dropdown.blade.php`, `category-search-dropdown.blade.php`, `brand-search-dropdown.blade.php`, `unit-search-dropdown.blade.php` — add support for a configurable modal-event name.
- **Livewire components**: `TaxQuickAddModal`, `CategoryQuickAddModal`, `BrandQuickAddModal`, `UnitQuickAddModal` — nested instances accept a configurable listener event name.
- **No breaking changes**: Page-level modals and all existing call-sites are unaffected. Only the nested instances inside `ProductQuickAddModal` change behavior.
- **Pages affected**: Any page that renders `ProductQuickAddModal` with nested quick-add modals (purchases/create, sales/create, products/create, products/edit).
