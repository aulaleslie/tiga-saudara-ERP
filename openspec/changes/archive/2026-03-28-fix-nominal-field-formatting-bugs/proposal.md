## Why

The `x-nominal-field` component has three critical bugs causing incorrect currency formatting on product create/edit pages:
1. Empty fields display blank instead of "0,00" on page load
2. User-entered values don't format on blur (e.g., "5000" stays "5000" instead of "Rp 5.000,00")
3. Edit page values corrupt on blur (e.g., "50000" becomes "0.5")

These bugs stem from flawed initialization logic that either skips maskMoney activation or passes incorrectly formatted strings to the masking engine. Fixing these is essential for consistent, reliable currency field UX across all product pricing inputs.

## What Changes

- **x-nominal-field initialization**: Always initialize maskMoney, even for empty/zero values
- **Empty field handling**: Show "0,00" placeholder for fields with no initial value
- **Blur formatting**: Pass raw numeric values to maskMoney('mask'), not pre-formatted strings with wrong decimal separators
- **Value synchronization**: Ensure hidden and visible inputs stay in sync throughout the lifecycle

## Capabilities

### New Capabilities

- `nominal-field-empty-value-handling`: Component correctly initializes and displays empty nominal fields with "0,00" placeholder, formats on blur when user enters values
- `nominal-field-safe-masking`: Component passes raw numbers to maskMoney's mask function, preventing locale-specific decimal separator conflicts (e.g., "50000.00" being interpreted as thousands separator in ID locale)

### Modified Capabilities

- `nominal-field-component`: Component now handles all value states (empty, zero, populated) consistently across create and edit pages

## Impact

- **Files modified**: `resources/views/components/nominal-field.blade.php` (JavaScript initialization logic)
- **Components affected**: All uses of `<x-nominal-field>` on product create/edit pages (5 price fields + conversion table)
- **No API/database changes**: Purely frontend formatting fix
- **Testing**: Manual testing on create/edit pages with various input scenarios (empty, zero, large numbers, decimals)
