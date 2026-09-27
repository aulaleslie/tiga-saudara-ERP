## 1. Consolidate conversion price component ownership

- [x] 1.1 Rewrite the conversion-price formatter IIFE in `resources/views/livewire/product/unit-configuration.blade.php` using vanilla JavaScript (no jQuery dependency). Use `querySelector`/`closest` for DOM-relative hidden-input lookup, `addEventListener` for focus/blur/input handlers, `el.value` for value access, and `dataset` for binding-guard state. Follow the proven pattern from `resources/views/components/nominal-field.blade.php`.
- [x] 1.2 Remove duplicate conversion-price masking and synchronization logic from `Modules/Product/Resources/views/products/create.blade.php` so the `UnitConfiguration` component becomes the single owner for conversion-row price behavior.
- [x] 1.3 Remove duplicate conversion-price masking and synchronization logic from `Modules/Product/Resources/views/products/edit.blade.php` while preserving existing non-conversion page behaviors such as submit locking and edit-specific mirrors.

## 2. Normalize and validate canonical conversion price values

- [x] 2.1 Add nested `conversions.*.price` normalization to `Modules/Product/Http/Requests/StoreProductInfoRequest.php` during `prepareForValidation()` without changing existing validation messages.
- [x] 2.2 Add the same nested `conversions.*.price` normalization to `Modules/Product/Http/Requests/UpdateProductRequest.php` so create and edit flows share the same canonical request contract.
- [x] 2.3 Verify that empty conversion-price inputs remain empty through normalization and still trigger the existing `required_with`, `numeric`, and `gt:0` rules correctly.

## 3. Preserve conversion price values across rerenders and validation round-trips

- [x] 3.1 Use native `dispatchEvent(new Event('input', { bubbles: true }))` (not jQuery `.trigger('input')`) for hidden-input sync so Livewire's deferred `wire:model` correctly propagates client-entered prices to the server component state. This ensures prices survive Livewire rerenders triggered by `addConversionRow`, `removeConversionRow`, or other component state changes.
- [x] 3.2 Verify old-input hydration for conversion rows on create and edit flows so validation redirects preserve entered conversion prices using the deterministic RP display format.
- [x] 3.3 Ensure final form submission still performs a last canonical sync for all visible conversion-price inputs before the request leaves the browser, using the vanilla JS `form.addEventListener('submit', ...)` pattern.

## 4. Add regression coverage

- [x] 4.1 Add request-level tests covering formatted and raw `conversions.*.price` input normalization for both store and update requests.
- [x] 4.2 Add regression coverage that a populated conversion price is submitted as a canonical numeric value on product create and does not regress to `null` after dynamic row interactions.
- [x] 4.3 Add regression coverage that conversion prices survive validation round-trips and edit-flow rerenders without losing their formatted display or canonical raw value.
