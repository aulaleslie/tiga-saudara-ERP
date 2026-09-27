## 1. Cart Tax Resolution Fallback

- [x] 1.1 Update `resolvePreferredPkpAutoTaxId` in `app/Livewire/Sale/ProductCart.php` to fallback to `resolveDefaultTaxId()`.
- [x] 1.2 Update `resolveDefaultTaxId` in `app/Livewire/Sale/ProductCart.php` to also fallback to the first available tax if PKP is enabled.

## 2. Validation Error Handling

- [x] 2.1 Update `ensureCartTaxesForPkp` in `app/Livewire/Sale/CreateForm.php` to throw the validation error generically, or dispatch a toast error, instead of binding to `paymentTermId`.
- [x] 2.2 Update `ensureCartTaxesForPkp` in `app/Livewire/Sale/EditForm.php` to match the exact same generic validation routing.
