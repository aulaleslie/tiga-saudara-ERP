# Proposal

## Why

Product conversion rows can silently refuse removal because persisted integer row keys are compared strictly with string button arguments. The create/edit editor also reindexes rows while nested unit dropdowns retain their mounted field names, creating a potential submission mismatch after removal and validation redirects.

## What Changes

- Normalize conversion row identities to stable, nonempty strings for persisted, newly added, and restored rows.
- Keep surviving unit dropdown field names aligned with the current conversion indexes after removal without losing their selected units.
- Preserve surviving row values through removal, form submission, and validation redirects.
- Add focused regression coverage and a targeted browser reproduction checklist.

## Capabilities

### New Capabilities

- `product-conversion-table-editor`: Reliable conversion row removal and coherent surviving-row form payloads on product create/edit, including validation round trips.

### Modified Capabilities

None. Existing conversion invariants and nominal formatting requirements remain applicable.

## Impact

Primary implementation surfaces are `app/Livewire/Product/UnitConfiguration.php`, `resources/views/livewire/product/unit-configuration.blade.php`, and the nested `Modules/Product/Livewire/UnitSearchDropdown.php` where needed. Focused tests belong alongside `tests/Feature/ProductUnitConfigurationComponentTest.php` and existing dropdown tests. No database changes, new dependencies, or full-suite test requirement are planned. Legacy conversion widgets and quick-add are outside scope unless investigation proves they participate in the affected create/edit flow.
