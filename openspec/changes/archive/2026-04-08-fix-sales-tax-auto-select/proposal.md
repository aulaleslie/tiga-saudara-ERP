## Why

Currently, when creating or editing a sales transaction in a PKP-enabled business, if a selected product doesn't have an explicit sales tax defined, the system fails to auto-select a fallback tax (giving up with a `null` value). Upon saving, this triggers a silent validation error incorrectly bound to the `paymentTermId` field, preventing the user from completing the transaction without any clear feedback. The exact same issue was previously fixed for Purchase transactions but was missed in Sales.

## What Changes

- Update the tax resolution logic in Sales to gracefully fallback to a default tax (or the first available tax) when PKP is enabled, matching the working Purchase behavior.
- Redirect the validation error in Sales create and edit forms so that if tax resolution fails, the user receives a visible error message rather than a silent failure bound to the `paymentTermId`.

## Capabilities

### New Capabilities
- `sale-tax-assignment`: Defines the tax auto-selection fallback logic and the presentation of the PKP tax validation error.

### Modified Capabilities
None

## Impact

- `app/Livewire/Sale/ProductCart.php`
- `app/Livewire/Sale/CreateForm.php`
- `app/Livewire/Sale/EditForm.php`
