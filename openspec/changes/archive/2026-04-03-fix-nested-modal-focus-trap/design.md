## Context

The purchases/create page (and similar pages) renders a `ProductQuickAddModal` which itself contains nested quick-add modals for Tax, Category, Brand, and Unit. The page also renders page-level instances of these same modals for use by the product cart.

Currently all quick-add modals use hardcoded global Livewire event names (e.g. `openTaxModal`). When a user clicks "Add Tax" inside the Product modal, `Livewire.dispatch('openTaxModal')` fires globally, causing **both** the page-level and nested instances to open. CoreUI's `_enforceFocus()` on the Product modal then steals focus from the page-level Tax modal (which is outside the Product modal's DOM), making inputs unusable.

Nested modals (Category, Brand, Unit) inside the Product modal have the same structural problem, though Tax is the most commonly reported.

### Current modal hierarchy
```
Page
├── ProductCart → uses page-level quick-add modals (✅ works)
├── ProductQuickAddModal (#productQuickAddModal, Bootstrap JS-managed)
│   ├── tax-search-dropdown → onclick dispatches 'openTaxModal' (global)
│   ├── category-search-dropdown → onclick dispatches 'openCategoryModal' (global)
│   ├── brand-search-dropdown → onclick dispatches 'openBrandModal' (global)
│   ├── unit-search-dropdown → wire:click calls openCreateModal() → dispatches 'openUnitModal' (global)
│   ├── <nested TaxQuickAddModal> listens 'openTaxModal'
│   ├── <nested CategoryQuickAddModal> listens 'openCategoryModal'
│   ├── <nested BrandQuickAddModal> listens 'openBrandModal'
│   └── <nested UnitQuickAddModal> listens 'openUnitModal'
├── <page-level TaxQuickAddModal> listens 'openTaxModal'
├── <page-level CategoryQuickAddModal> listens 'openCategoryModal' (if present)
├── <page-level BrandQuickAddModal> listens 'openBrandModal' (if present)
└── <page-level UnitQuickAddModal> listens 'openUnitModal' (if present)
```

### Key constraint
CoreUI's `_enforceFocus()` checks `this._element.contains(event.target)`. If the Tax modal lives **inside** the Product modal DOM, focus works. If it's the page-level instance outside that DOM tree, focus is stolen.

## Goals / Non-Goals

**Goals:**
- Users can successfully create tax/category/brand/unit entities from inside the Product Quick-Add modal
- Only the nested modal instance opens when triggered from inside the Product modal
- Only the page-level modal instance opens when triggered from the product cart or other page-level contexts
- Approach is consistent across all four entity types (tax, category, brand, unit)

**Non-Goals:**
- Refactoring the Bootstrap/CoreUI modal system or replacing the focus trap mechanism
- Changing the overall modal architecture (page-level modals remain as-is)
- Supporting arbitrarily deep modal nesting (only 2 levels: page → product modal → entity modal)

## Decisions

### Decision 1: Configurable event name via component property

**Choice**: Add a `modal-event` (rendered as `$modalEvent`) public property to each search-dropdown component and each quick-add modal component. Defaults to the current global name.

**Rationale**: The `dispatch-to` prop pattern already exists in these components for scoping selection callbacks. Adding `modal-event` follows the same convention. No new architectural patterns needed.

**Alternatives considered**:
- *JS focus-trap override*: Fragile, couples to CoreUI internals, breaks on upgrades
- *Remove page-level modals and use only nested*: Would break product cart's usage which has no parent modal
- *Single shared modal with deferred rendering*: Major refactor, over-engineered for this problem

### Decision 2: Naming convention for scoped events

**Choice**: Nested instances use `openNested<Entity>Modal` (e.g. `openNestedTaxModal`, `openNestedCategoryModal`). The dropdown's `modal-event` prop controls which event is dispatched.

**Rationale**: Clear, grep-able, and self-documenting. The `Nested` prefix signals this is for the within-product-modal context.

### Decision 3: Apply to all four entity types simultaneously

**Choice**: Fix tax, category, brand, and unit dropdowns/modals in one change.

**Rationale**: All four have identical structure and identical bug. Fixing only tax would leave the others broken and create inconsistency.

### Decision 4: Nested modals accept event name as component parameter

**Choice**: The quick-add modal Livewire components accept a `listenEvent` property (default: current event name). The nested instances in `product-quick-add-modal.blade.php` pass the scoped event name.

**Rationale**: This lets the same component class serve both page-level and nested roles without duplication.

## Risks / Trade-offs

- **[Low] Third-party pages using global event names** → Mitigation: Defaults unchanged; only explicitly configured nested instances use scoped events. No breaking change for existing call-sites.
- **[Low] Two modal instances still exist in DOM** → Mitigation: Only one opens per trigger. The scoped event targets only the nested instance; the global event targets only the page-level instance.
- **[Low] Unit dropdown uses `wire:click` method instead of inline `onclick`** → Mitigation: The `openCreateModal()` PHP method will read `$this->modalEvent` to decide which event to dispatch, keeping the same pattern.
