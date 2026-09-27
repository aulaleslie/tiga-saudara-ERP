## Why

PKP (Pengusaha Kena Pajak) businesses are required to apply taxes to all purchase items. Currently, if no tax is marked as "Default" in the database and a product doesn't have a specific purchase tax assigned, the backend auto-selection logic returns `null`. 

However, the browser UI visually "auto-selects" the first available tax in the dropdown because the placeholder option ("Wajib Pilih Pajak") is disabled. This creates a state mismatch: the user sees a valid selection in the UI, but the server-side cart object remains `null`. Consequently, when the user submits the form, the backend validation fails with an error stating that the product must have a tax selected.

## What Changes

- Modify the `ProductCart` component logic to fallback to the first available tax in the system when the business is PKP and no explicit default or product-specific tax is found.
- This ensures that the backend state immediately matches the visual state the user sees in the browser.
- Update `CreateForm` validation to provide clear feedback if the system truly has zero taxes available for a PKP business.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `purchase-creation`: Update PKP tax fallback logic to automatically select the first available tax if no explicit default or product-specific tax is configured.

## Impact

- `App\Livewire\Purchase\ProductCart`: Implementation of the robust fallback in `resolveDefaultTaxId`.
- `App\Livewire\Purchase\CreateForm`: Refined validation logic in `ensureCartTaxesForPkp`.
- **User Experience**: Elimination of the "false selection" bug where the UI looks correct but submission fails.
