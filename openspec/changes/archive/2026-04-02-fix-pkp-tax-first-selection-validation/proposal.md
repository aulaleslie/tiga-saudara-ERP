## Why

PKP purchase and sale carts currently allow users to choose a tax visually, but the first manual tax selection can still be treated as missing during cart recalculation and submission. This creates a false validation failure in create and edit flows, blocking valid transactions and making tax assignment feel unreliable.

## What Changes

- Update purchase and sale cart tax selection handling so the first manually selected tax is applied immediately and persisted to the cart row in the same interaction.
- Preserve explicit manual tax assignment behavior in PKP mode without reintroducing automatic default-tax fallback for taxless rows.
- Add regression coverage for purchase and sale cart tax selection so stale Livewire state cannot cause false "tax not selected" validation failures.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `tax-assignment`: PKP purchase and sale carts must persist the user's first explicit tax selection immediately so subsequent validation and totals use the selected tax instead of stale null cart state.

## Impact

- Affected code: `resources/views/livewire/{purchase,sale}/product-cart.blade.php`, `app/Livewire/{Purchase,Sale}/ProductCart.php`
- Affected behavior: PKP purchase create/edit and sale create/edit cart tax selection, tax recalculation, and submit validation
- Affected tests: Livewire cart tax-selection regression coverage for purchase and sale flows
