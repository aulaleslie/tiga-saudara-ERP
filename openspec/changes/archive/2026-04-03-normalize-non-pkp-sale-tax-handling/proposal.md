## Why

Non-PKP sales can still persist tax-inflated economic data when edit flows restore tax-bearing cart state and later trust that state during save. The purchase module now normalizes this at the write boundary, but the sale module still risks silently storing economically inconsistent non-PKP totals even when tax is no longer meant to apply.

## What Changes

- Add a shared sale write-side normalization step that enforces non-PKP sale persistence rules before sale headers and details are saved.
- Recompute non-PKP sale line subtotals and sale header totals using tax-excluded values when hidden or restored tax-bearing cart state is present.
- Apply the same non-PKP normalization behavior across sale create and sale edit flows, including Livewire and controller-backed entry points.
- Harden sale edit cart restore behavior so non-PKP edit flows do not silently preserve hidden tax-bearing state that conflicts with persisted non-tax economics.
- Add regression coverage for non-PKP sale create and edit flows so silent total inflation cannot recur.

## Capabilities

### New Capabilities

### Modified Capabilities
- `tax-assignment`: Extend sale requirements so non-PKP sale writes ignore hidden tax-bearing cart state and recompute persisted amounts using tax-excluded values.

## Impact

- `app/Livewire/Sale/CreateForm.php`
- `app/Livewire/Sale/EditForm.php`
- `app/Livewire/Sale/ProductCart.php`
- `Modules/Sale/Http/Controllers/SaleController.php`
- `Modules/Sale/Services/SaleService.php`
- `Modules/Sale/Services/SaleCartAggregator.php`
- Sale feature and Livewire regression tests covering non-PKP create and edit flows
