## Context

The Sales cart UI has an auto-calculate mechanism for taxes. However, if PKP is enabled, `app/Livewire/Sale/ProductCart.php` fails to set a default tax when the product itself doesn't define one. This behaves differently from the 'Purchase' module, which was successfully fixed previously. As a result, when users try to save, a `ValidationException` is artificially bound to the `paymentTermId` field, making the error completely invisible unless the user spots a generic warning or if `paymentTermId` has its own error display.

## Goals / Non-Goals

**Goals:**
- Unify the tax auto-selection fallback logic in Sales with Purchase.
- Make the PKP tax validation error visible by un-binding it from `paymentTermId` and throwing a generalized session flash error, or binding it directly to a generic error message key so that the form properly interrupts.

**Non-Goals:**
- Changing how PKP taxes work fundamentally.
- Modifying tax behavior in SalesReturns and PurchaseReturns.

## Decisions

**1. Update `resolvePreferredPkpAutoTaxId` and `resolveDefaultTaxId` in `app/Livewire/Sale/ProductCart.php`**
- _Rationale_: This establishes parity with Purchase. The function will try to resolve the product-specific tax, and if that fails, delegating it to `resolveDefaultTaxId()`. `resolveDefaultTaxId()` will check for an explicit `is_default` tax, and if not present, will fall back to the first available tax in the system (when PKP is enabled).

**2. Update `ensureCartTaxesForPkp` Validation Handling**
- _Rationale_: Instead of throwing a validation error explicitly to `paymentTermId`, it should dispatch a flash error or simply `abort`/`validate` on a neutral field that the component displays globally. Typically, similar errors are handled with `$this->dispatch('notify', ['type' => 'error', 'message' => '...'])` to show a toast, or a generalized form error.

## Risks / Trade-offs

- **Risk:** Existing sales flows might behave differently if they currently expect null taxes.
  - **Mitigation:** PKP businesses *require* a tax. Validations already enforce this. Giving them a tax instead of a silent error only allows the transaction to succeed.
