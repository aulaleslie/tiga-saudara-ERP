## Why

Non-PKP purchases can still persist tax-bearing data when hidden cart state carries `tax_id` from product defaults or restored purchase details. The controller path already strips some incoming tax, but the Livewire create and edit flows bypass that protection and can save inflated purchase totals even though the tax selector is hidden from the user.

## What Changes

- Add a shared purchase write-side normalization step that enforces non-PKP purchase persistence rules before header and detail rows are saved.
- Recompute non-PKP purchase line subtotals and purchase header totals using tax-excluded values when hidden tax-bearing cart state is present.
- Apply the same normalization behavior across purchase create and purchase edit flows, including Livewire and controller-backed entry points.
- Harden purchase cart/UI behavior so non-PKP purchase flows do not silently retain or re-seed hidden tax state from product defaults or restored purchase details.
- Align non-PKP purchase UI behavior so tax-only fields do not remain editable where the workflow no longer supports tax assignment.

## Capabilities

### New Capabilities

### Modified Capabilities
- `tax-assignment`: Extend purchase requirements so non-PKP purchase writes ignore hidden tax state and recompute persisted amounts using tax-excluded values.

## Impact

- `app/Livewire/Purchase/CreateForm.php`
- `app/Livewire/Purchase/EditForm.php`
- `app/Livewire/Purchase/ProductCart.php`
- `Modules/Purchase/Http/Controllers/PurchaseController.php`
- `resources/views/livewire/purchase/edit-form.blade.php`
- Purchase feature tests covering Livewire create/edit and controller-backed flows
