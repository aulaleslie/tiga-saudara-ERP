## Why

POS finalize currently posts a single unified sale/payment even when the cart contains lines from different ownership sources, locations, and tax contexts. This causes accounting and inventory reconciliation risk, so Phase 3 is needed now to make checkout posting source-aware while keeping cashier flow unchanged.

## What Changes

- Add split-aware checkout posting that groups finalized checkout lines by `source_setting_id + source_location_id + tax_bucket`.
- Generate one sale document, one payment allocation, and dispatch records per split group within one finalize action.
- Persist checkout-to-sale mapping for every group to support reconciliation and deterministic idempotent replay.
- Extend finalize response with grouped outputs (`split_groups`, `sales`, `sale_payments`) while preserving legacy compatibility fields (`sale_id`, `sale_payment_id`, `dispatch_ids`) from the first group.
- Apply tax fallback for taxable lines: default tax first, otherwise latest active tax.
- Keep current UI and endpoint shape for cashier finalize flow (`POST /pos/sell/checkout/finalize`).

## Capabilities

### New Capabilities
- `pos-checkout-split-posting`: Finalize one checkout into multiple sales/payments/dispatches based on source and tax bucket with exact total reconciliation.
- `pos-checkout-split-idempotency`: Ensure replay of finalize with the same idempotency key returns the same split map and avoids duplicate posting.
- `pos-checkout-split-response-compatibility`: Extend finalize payload with grouped results while retaining legacy top-level compatibility fields.

### Modified Capabilities
- None.

## Impact

- Affected backend services:
  - `Modules/Pos/Services/FinalizePosCheckoutService.php`
  - `Modules/Pos/Services/Contracts/PosCheckoutPostingAdapter.php`
  - `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php` (compatibility path)
  - `Modules/Pos/Services/ResolvePosStockAllocationsService.php`
  - new split planner/payment split/adapter services under `Modules/Pos/Services/`
- Affected provider binding:
  - `Modules/Pos/Providers/PosServiceProvider.php`
- Data model changes:
  - new `pos_checkout_sales` mapping table
  - split summary metadata support in `pos_checkouts`
- API impact:
  - backward-compatible response extension for `POST /pos/sell/checkout/finalize`
- Testing impact:
  - new split posting, idempotency replay, and tax fallback tests; rerun existing finalize/serial/tax regression tests.
