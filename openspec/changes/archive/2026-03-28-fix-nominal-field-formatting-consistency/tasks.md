## 1. Create Reusable Nominal Field Component

- [x] 1.1 Create `/resources/views/components/nominal-field.blade.php` with full implementation including props, currency settings integration, and internal maskMoney handler binding
- [x] 1.2 Add inline documentation explaining visible/hidden input pattern and maskMoney lifecycle
- [x] 1.3 Test component renders correctly with all prop combinations (enabled, disabled, with error, without error)

## 2. Update Product Create Form

- [x] 2.1 Update create.blade.php: Replace inline purchase_price input with `<x-nominal-field name="purchase_price" label="Harga Beli" :value="old('purchase_price', $purchase_price)" />` (via product-price-setup component)
- [x] 2.2 Update create.blade.php: Replace inline sale_price input with `<x-nominal-field name="sale_price" label="Harga Jual" :value="old('sale_price', $sale_price)" />` (via sale-price-setup component)
- [x] 2.3 Update create.blade.php: Replace inline tier_1_price input with `<x-nominal-field name="tier_1_price" label="Harga Jual Partai Besar" :value="old('tier_1_price', $tier_1_price)" />` (via sale-price-setup component)
- [x] 2.4 Update create.blade.php: Replace inline tier_2_price input with `<x-nominal-field name="tier_2_price" label="Harga Jual Reseller" :value="old('tier_2_price', $tier_2_price)" />` (via sale-price-setup component)
- [x] 2.5 Remove maskMoney initialization for the 4 main prices from create.blade.php script section (lines around 233-265)
- [x] 2.6 Keep maskMoney initialization for conversion-price-input class (still in use in conversion table)
- [x] 2.7 Test create page: all 4 main prices behave correctly (raw on focus, formatted on blur)

## 3. Update Product Edit Form

- [x] 3.1 Update edit.blade.php: Replace inline purchase_price input with `<x-nominal-field .../>` (via product-price-setup component)
- [x] 3.2 Update edit.blade.php: Replace inline sale_price input with `<x-nominal-field .../>` (via sale-price-setup component)
- [x] 3.3 Update edit.blade.php: Replace inline tier_1_price input with `<x-nominal-field .../>` (via sale-price-setup component)
- [x] 3.4 Update edit.blade.php: Replace inline tier_2_price input with `<x-nominal-field .../>` (via sale-price-setup component)
- [x] 3.5 Remove `maskNow()` call from edit.blade.php (was applying maskMoney immediately)
- [x] 3.6 Remove maskMoney initialization for the 4 main prices from edit.blade.php script section
- [x] 3.7 Keep maskMoney initialization for conversion-price-input class and the special disabled field mirrors logic
- [x] 3.8 Test edit page: all 4 main prices now show raw on focus (fixing the broken behavior)

## 4. Fix Conversion Table Prices

- [x] 4.1 Update unit-configuration.blade.php: Remove `wire:focus="showRawPrice({{ $index }})"` from the visible price input
- [x] 4.2 Update unit-configuration.blade.php: Remove `wire:blur="syncPrice({{ $index }})"` from the visible price input
- [x] 4.3 Update unit-configuration.blade.php: Remove `wire:model="displayPrices.{{ $index }}"` from the visible price input
- [x] 4.4 Update unit-configuration.blade.php: Change visible price input value binding to use static data: `value="{{ $displayPrices[$index] ?? '' }}"`
- [x] 4.5 Ensure hidden input still has `wire:model="conversions.{{ $index }}.price"` for Livewire data binding
- [x] 4.6 Add `data-hidden` attribute to visible input pointing to the hidden input ID
- [x] 4.7 Test conversion table: price fields work correctly, Livewire updates don't break focus/blur state

## 5. Clean Up Livewire Component

- [x] 5.1 Update UnitConfiguration.php: Remove `showRawPrice()` method (no longer needed, jQuery handles it)
- [x] 5.2 Update UnitConfiguration.php: Remove `syncPrice()` method (no longer needed, jQuery handles it)
- [x] 5.3 Update UnitConfiguration.php: Verify `updatedDisplayPrices()` is no longer called (can be removed in future cleanup, but leave for now)
- [x] 5.4 Update UnitConfiguration.php: Add comment explaining that formatting is now handled by jQuery maskMoney, not Livewire

## 6. Form Submission Handling

- [x] 6.1 Verify create.blade.php form submission still extracts raw numbers for all 4 main prices + conversion prices (code should still work, now delegated to component)
- [x] 6.2 Verify edit.blade.php form submission still extracts raw numbers for all 4 main prices + conversion prices
- [x] 6.3 Test form submission on create: backend receives raw numeric values (no currency symbols)
- [x] 6.4 Test form submission on edit: backend receives raw numeric values (no currency symbols)

## 7. Testing & Validation

- [x] 7.1 Create manual test plan covering: page load (formatted), focus (raw), blur (formatted), submit (raw numeric to backend)
- [x] 7.2 Test all 5 fields on create page (4 main + 1 in conversion table)
- [x] 7.3 Test all 5 fields on edit page (4 main + 1 in conversion table)
- [x] 7.4 Test disabled fields (edit page stock-managed=false should disable some fields)
- [x] 7.5 Test validation errors: field shows error message alongside formatted value
- [x] 7.6 Test conversion table: add row, edit price, remove row - prices always behave correctly
- [x] 7.7 Test edge cases: empty values, zero values, very large numbers, decimal rounding
- [x] 7.8 Test currency switching (if settings allow): verify new symbol/separators apply correctly

## 8. Documentation & Code Review

- [x] 8.1 Add inline comments to nominal-field.blade.php explaining the visible/hidden input pattern
- [x] 8.2 Add comment in create.blade.php explaining that maskMoney is now delegated to x-nominal-field component
- [x] 8.3 Add comment in edit.blade.php explaining that maskNow() was removed to prevent focus/blur conflicts
- [x] 8.4 Add comment in unit-configuration.blade.php explaining why wire:focus/blur were removed and jQuery now handles formatting
- [x] 8.5 Update any developer documentation if exists (no component-specific docs found; inline comments sufficient)
- [x] 8.6 Prepare commit message explaining the changes and why they fix the problem

## 9. Final Verification

- [x] 9.1 Run all product tests (if tests exist): `php artisan test Tests/Unit/ProductTest.php` or similar
- [x] 9.2 Verify no console errors in browser (F12 Dev Tools)
- [x] 9.3 Test in different browsers if possible (Chrome, Firefox, Safari)
- [x] 9.4 Verify git status: only intended files modified, no accidental changes
- [x] 9.5 Create git commit(s) with clear messages
- [x] 9.6 Mark change as complete for archiving
