## Why

The bundle item editor on product bundle create/edit pages has two user-blocking bugs: edits to a row's *informational item price* are silently discarded on save, and clicking the row "Hapus" button does not reliably remove a row (especially non-last rows). Both stem from a Livewire 3 wiring mistake in the `BundleTable` component combined with a plain HTML `<form>` submit, leaving users unable to maintain accurate bundle composition.

## What Changes

- Make the per-row `quantity` and `informational_item_price` inputs sync to the Livewire component on blur, so the hidden-input mirrors that the native form submits hold the user's typed values.
- Replace `:key="$rowKey"` with `wire:key="psd-{{ $rowKey }}"` on the nested `<livewire:modules.product.product-search-dropdown />` so each row's product picker is identified by row identity (not list position) and survives row removal correctly.

## Capabilities

### New Capabilities
- `product-bundle-table-editor`: User-facing requirements for the bundle item editor used on product bundle create/edit pages — what the user must be able to enter and remove and how that data must reach the persisted form submission.

### Modified Capabilities
<!-- None — no existing spec covers this UI. -->

## Impact

- Code:
  - [resources/views/livewire/product/bundle-table.blade.php](resources/views/livewire/product/bundle-table.blade.php) — input bindings and child component key.
- No controller, model, route, migration, or API changes.
- No data migration. No breaking changes to persisted bundle data.
- Affected user flows: `Modules/Product/Resources/views/bundles/create.blade.php` and `Modules/Product/Resources/views/bundles/edit.blade.php`.
