## Why

POS terminal configuration fields for currency values (`close_variance_approval_threshold`, `cash_threshold`) currently display as plain decimal numbers, making it difficult for users to read large amounts at a glance. Formatting them as currency (e.g., "Rp 1.000,00") improves usability and reduces data entry errors by providing visual feedback aligned with Indonesian locale conventions.

## What Changes

- Add client-side currency formatting to the POS terminal create/edit form
- Economic fields display formatted currency on blur (e.g., "Rp 1.000,00") for readability
- Fields return to plain number format on focus for easy editing
- Form submission extracts raw numeric values to maintain database compatibility
- Uses existing jQuery maskMoney library already present in the codebase

## Capabilities

### New Capabilities
- `currency-input-formatting`: Client-side currency formatting for economic input fields with focus/blur behavior

### Modified Capabilities
<!-- No existing capability requirements are changing -->

## Impact

- **Files Modified**: `Modules/Pos/Resources/views/terminals/_form.blade.php` (or layout wrapper)
- **Dependencies**: Requires `jquery-mask-money.js` (already present at `public/js/jquery-mask-money.js`)
- **Browser Behavior**: JavaScript-based, no backend changes required
- **Database**: No schema changes; form submission extracts numeric values before posting
