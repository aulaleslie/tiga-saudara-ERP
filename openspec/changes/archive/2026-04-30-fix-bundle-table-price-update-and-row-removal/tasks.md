## 1. Fix input sync on form submit

- [x] 1.1 In [resources/views/livewire/product/bundle-table.blade.php](resources/views/livewire/product/bundle-table.blade.php), change the quantity input's `wire:model="items.{{ $index }}.quantity"` to `wire:model.blur="items.{{ $index }}.quantity"`.
- [x] 1.2 In the same file, change the price input's `wire:model="items.{{ $index }}.informational_item_price"` to `wire:model.blur="items.{{ $index }}.informational_item_price"`.

## 2. Fix nested product-search-dropdown keying

- [x] 2.1 In [resources/views/livewire/product/bundle-table.blade.php](resources/views/livewire/product/bundle-table.blade.php), replace `:key="$rowKey"` on `<livewire:modules.product.product-search-dropdown ... />` with `wire:key="psd-{{ $rowKey }}"`. Keep `:index="$rowKey"` and `:selected="$item['product_id']"` unchanged.

## 3. Manual verification

- [x] 3.1 On the bundle **edit** page, change a row's `informational_item_price`, click "Simpan Perubahan", and confirm the saved record reflects the new price.
- [x] 3.2 On the bundle **edit** page, change a row's `quantity`, click "Simpan Perubahan", and confirm the saved record reflects the new quantity.
- [x] 3.3 On the bundle **edit** page, with three rows A/B/C populated, click "Hapus" on row B; confirm A and C remain with their original products, quantities, and prices.
- [x] 3.4 On the bundle **create** page, add three rows, populate each, remove the middle row, and confirm the remaining two rows keep their selected products and values.
- [x] 3.5 On the bundle **edit** page, remove a row and submit; confirm the persisted bundle no longer contains an item for the removed row.

## 4. Update OpenSpec

- [x] 4.1 Run `openspec validate fix-bundle-table-price-update-and-row-removal --strict` and address any issues.
- [x] 4.2 After implementation and verification, run `/opsx:archive fix-bundle-table-price-update-and-row-removal` to archive this change.
