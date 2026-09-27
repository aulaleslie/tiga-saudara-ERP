## 1. Add configurable event props to quick-add modal components

- [x] 1.1 Add `listenEvent` public property to `TaxQuickAddModal.php` with default `'openTaxModal'`; update `$listeners` to use it dynamically via `getListeners()`
- [x] 1.2 Add `listenEvent` public property to `CategoryQuickAddModal.php` with default `'openCategoryModal'`; update listeners
- [x] 1.3 Add `listenEvent` public property to `BrandQuickAddModal.php` with default `'openBrandModal'`; update listeners
- [x] 1.4 Add `listenEvent` public property to `UnitQuickAddModal.php` with default `'openUnitModal'`; update listeners

## 2. Add configurable modal-event props to search-dropdown components

- [x] 2.1 Add `modalEvent` property to `TaxSearchDropdown.php` (default `'openTaxModal'`); update blade to dispatch `$modalEvent` instead of hardcoded event
- [x] 2.2 Add `modalEvent` property to `CategorySearchDropdown.php` (default `'openCategoryModal'`); update blade
- [x] 2.3 Add `modalEvent` property to `BrandSearchDropdown.php` (default `'openBrandModal'`); update blade
- [x] 2.4 Update `UnitSearchDropdown.php` `openCreateModal()` method to dispatch `$this->modalEvent` (default `'openUnitModal'`) instead of hardcoded event; update blade if needed

## 3. Wire scoped events in ProductQuickAddModal

- [x] 3.1 Update `product-quick-add-modal.blade.php` to pass `modal-event="openNestedTaxModal"` to nested tax-search-dropdown instances
- [x] 3.2 Update `product-quick-add-modal.blade.php` to pass `modal-event="openNestedCategoryModal"` to nested category-search-dropdown
- [x] 3.3 Update `product-quick-add-modal.blade.php` to pass `modal-event="openNestedBrandModal"` to nested brand-search-dropdown
- [x] 3.4 Update `product-quick-add-modal.blade.php` to pass `modal-event="openNestedUnitModal"` to nested unit-search-dropdown instances
- [x] 3.5 Update nested quick-add modal instances in `product-quick-add-modal.blade.php` to pass scoped `listen-event` props (e.g. `listen-event="openNestedTaxModal"`)

## 4. Verification

- [x] 4.1 Test: Open Product modal from purchases/create → click "add tax" → verify only nested tax modal opens and inputs are focusable
- [x] 4.2 Test: From product cart on purchases/create → click "add tax" → verify page-level tax modal opens and works
- [x] 4.3 Test: Inside Product modal → add category, brand, unit → verify all nested modals open correctly and inputs work
- [x] 4.4 Test: Submit each nested modal form → verify entity is created and dropdown refreshes
