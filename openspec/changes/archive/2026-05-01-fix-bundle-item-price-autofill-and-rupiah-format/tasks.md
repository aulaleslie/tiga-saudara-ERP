## 1. Price Resolution and Guard Behavior

- [x] 1.1 Update bundle item selection handling in `app/Livewire/Product/BundleTable.php` to resolve autofill price from active-setting `product_prices.sale_price` only (non-tier).
- [x] 1.2 Add explicit missing-price guard behavior in `app/Livewire/Product/BundleTable.php` so rows with unresolved active-setting sale price are surfaced clearly instead of silently ambiguous fallback.
- [x] 1.3 Ensure product selection pathway from `Modules/Product/Livewire/ProductSearchDropdown.php` to `BundleTable` preserves context needed for setting-scoped price resolution.

## 2. Currency Field UX for Bundle Item Price

- [x] 2.1 Replace bundle item `informational_item_price` visible control in `resources/views/livewire/product/bundle-table.blade.php` with text-based focus/blur currency interaction compatible with `Rp X.XXX,XX`.
- [x] 2.2 Reuse/align existing currency parsing-formatting pattern used in `resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php` so behavior is consistent across product-related forms.
- [x] 2.3 Verify create and edit screens (`Modules/Product/Resources/views/bundles/create.blade.php` and `Modules/Product/Resources/views/bundles/edit.blade.php`) load bundle table behavior consistently without conflicting price scripts.

## 3. Payload Normalization and Validation Contract

- [x] 3.1 Keep canonical numeric `items[*][informational_item_price]` submission path in `resources/views/livewire/product/bundle-table.blade.php` hidden inputs aligned with Livewire state.
- [x] 3.2 Confirm `Modules/Product/Http/Controllers/ProductBundleController.php` validation contract (`required|numeric|min:0`) continues to receive raw numeric values after currency display behavior changes.
- [x] 3.3 Add/adjust row-level validation message handling in bundle views so users can correct invalid/missing informational prices predictably.

## 4. Verification and Regression Coverage

- [x] 4.1 Add/extend tests for bundle create flow covering: sale-price autofill from active setting, missing-price guard path, and persisted numeric informational price.
- [x] 4.2 Add/extend tests for bundle edit flow covering: existing informational price display, re-selection autofill behavior, and updated persistence.
- [x] 4.3 Add UI interaction coverage for focus/blur formatting (`Rp` on blur, raw on focus) and immediate-save submission correctness for informational item price.
- [x] 4.4 Run targeted Product bundle test filters and document verification notes in the change artifacts before apply.
