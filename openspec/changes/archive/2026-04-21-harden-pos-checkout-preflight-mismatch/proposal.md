## Why

Kasir dapat masuk ke alur pembayaran bertahap lalu baru gagal pada tahap finalize ketika stok/serial tidak lagi valid. Urutan ini menimbulkan pengalaman menyesatkan karena operator merasa transaksi sudah berhasil diproses sebelum sistem menolak mismatch kuantitas/stock.

## What Changes

- Add a checkout preflight validation step that runs when `Pilih Pembayaran` is clicked, before staged payment modal is opened.
- Return structured mismatch details for quantity/stock/serial failures so UI can present a focused mismatch dialog.
- Show a dedicated mismatch modal on preflight failure and keep cashier in POS cart context after dialog close.
- Prevent staged payment modal opening whenever preflight reports invalid cart fulfillment.
- Align staged checkout flow so success-like UI is shown only after authoritative finalize success.

## Capabilities

### New Capabilities
- `pos-checkout-preflight-validation`: Validate cart fulfillability (serial-count match and stock availability) before opening payment flow, with actionable mismatch payload for POS UI dialog.

### Modified Capabilities
- `pos-staged-checkout-wiring`: Change checkout-button behavior from immediate staged modal open to preflight-gated open.
- `pos-checkout-serial-stock-validation`: Reuse/extend finalize-grade stock and serial validation to support pre-payment preflight responses.

## Impact

- Frontend: `Modules/Pos/Resources/views/sell.blade.php`, staged checkout wiring, new mismatch modal view/behavior.
- Backend: POS checkout controller/routes and validation service path for reusable preflight checks.
- API: new preflight endpoint and structured error contract consumed by POS shell.
- Tests: feature tests for preflight pass/fail flows, modal-gating behavior, and regression coverage for staged/finalize ordering.
