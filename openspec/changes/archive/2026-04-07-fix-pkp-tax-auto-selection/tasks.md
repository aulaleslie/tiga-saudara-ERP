## 1. Implement Fallback Logic in ProductCart

- [x] 1.1 Update `resolveDefaultTaxId` in `App\Livewire\Purchase\ProductCart` to return the first available tax in the collection if no explicit default is found and `isPkp` is true.

## 2. Refine Validation in CreateForm

- [x] 2.1 Update `ensureCartTaxesForPkp` in `App\Livewire\Purchase\CreateForm` to check for total available taxes in the system before validating individual lines.
- [x] 2.2 Improve the validation error message when zero taxes are found in the system for PKP businesses.

## 3. Verification

- [x] 3.1 Verify that selecting a product with no specific tax auto-selects the first available tax when no default is set.
- [x] 3.2 Verify that the UI dropdown correctly reflects the auto-selected tax.
- [x] 3.3 Verify that form submission succeeds when an auto-selected fallback tax is used.
