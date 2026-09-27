## Why

Currently, multiple payment methods can have the `is_cash` flag set to `true`. This can lead to ambiguity in POS operations where a single cashier-based cash method is expected. 

## What Changes

We will implement strict validation when creating or editing payment methods to ensure that only one record in the `payment_methods` table can have `is_cash` set to `true`. This validation will prevent users from enabling the cash flag if another method already has it.

## Capabilities

### New Capabilities
- `only-one-cash-method`: Strictly enforces a single cash-based payment method at the system level.

### Modified Capabilities
<!-- Existing capabilities whose REQUIREMENTS are changing (not just implementation).
     Only list here if spec-level behavior changes. Each needs a delta spec file.
     Use existing spec names from openspec/specs/. Leave empty if no requirement changes. -->

## Impact

- `Modules/Setting/Http/Controllers/PaymentMethodController.php`: Updated to use new validation logic.
- `Modules/Setting/Http/Requests`: Introduce `StorePaymentMethodRequest` and `UpdatePaymentMethodRequest` to encapsulate validation.
- `Modules/Setting/Entities/PaymentMethod.php`: (Optional) Possible model level validation as a safety layer.
