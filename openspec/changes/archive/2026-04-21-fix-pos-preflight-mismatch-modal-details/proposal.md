## Why

Checkout preflight already returns structured stock/serial mismatch details, but the POS sell page still falls back to a generic `Gagal Validasi` alert when `Pilih Pembayaran` is clicked. This hides actionable product-level shortages and contradicts the intended cashier workflow of correcting the cart before entering payment.

## What Changes

- Preserve structured preflight error payload (`code`, `message`, `details`) in POS sell frontend request handling instead of collapsing failures into plain `Error(message)`.
- Ensure preflight failures with `details.unfulfilled_lines` or `details.invalid_lines` always trigger the dedicated mismatch modal and keep staged payment modal closed.
- Normalize mismatch-modal rendering so line display works with authoritative backend fields (`product_id`, `product_code`, `requested_qty`, `allocated_qty`, `reason_code`) and computes shortage consistently.
- Add regression coverage for the failure path from `Pilih Pembayaran` click through modal rendering to prevent silent fallback to generic validation alerts.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-staged-checkout-wiring`: Strengthen checkout-button failure handling so preflight structured mismatch responses always drive modal behavior and block staged modal open.
- `pos-checkout-preflight-validation`: Clarify consumable failure contract for UI rendering, including stable mismatch line diagnostics used by POS cart modal.

## Impact

- Frontend: `Modules/Pos/Resources/views/sell.blade.php` and mismatch modal rendering path.
- API contract usage: POS preflight response consumption (no endpoint shape break expected; contract handling is hardened).
- Tests: POS feature/browser-like flow checks for preflight failure UX and detail visibility.
- Operational UX: Cashier sees line-level mismatch detail instead of generic failure text during payment entry.
