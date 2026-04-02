## Why

PKP purchase and sale carts still fail tax-required validation in mixed-selection cases where users assign taxes across multiple rows and at least one row uses the tax marked as default while another uses a non-default tax. The current fix covers a single first-time selection, but it does not yet guarantee that every visible tax choice is persisted per line before recalculation and submit.

## What Changes

- Fix PKP purchase and sale cart tax selection persistence so each row's selected tax is stored deterministically even when multiple cart rows use different taxes in the same interaction.
- Eliminate false equivalence between a visually prioritized default tax option and an explicitly persisted tax assignment.
- Ensure downstream recalculation, tax-included toggles, and submit validation always read the same per-line tax state that the user last selected.
- Add regression coverage for mixed-row PKP scenarios, including combinations of default and non-default tax selections in create and edit flows.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `tax-assignment`: PKP purchase and sale carts must persist and honor explicit per-line tax selections consistently across multiple rows, including mixed default and non-default tax choices.

## Impact

- Affected code: `app/Livewire/{Purchase,Sale}/ProductCart.php`, `app/Livewire/{Purchase,Sale}/{CreateForm,EditForm}.php`, `resources/views/livewire/{purchase,sale}/product-cart.blade.php`
- Affected behavior: PKP purchase and sale cart tax selection, tax recalculation, tax-included toggles, and submit validation when multiple cart rows have mixed tax choices
- Affected tests: Livewire PKP cart regression coverage for mixed-row selection and submit flows in purchase and sale modules
