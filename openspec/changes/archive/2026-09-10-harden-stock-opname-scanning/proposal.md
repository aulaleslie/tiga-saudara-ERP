## Why

The stock-opname editor currently sends each Enter-triggered scan directly through a shared Livewire input, allowing rapid scans to overlap, concatenate, or overwrite increments. Its editable Good/Bad count inputs can also display stale values after Livewire has already updated the authoritative draft, and its location state does not enforce the active-setting boundary before scan-time stock data is read.

## What Changes

- Serialize stock-opname barcode and serial submissions through a FIFO browser queue so every captured scan is processed in order against the state produced by the preceding scan.
- Pass the captured scan value explicitly to Livewire and clear the visible scanner input immediately, preventing in-flight input concatenation or response-driven erasure.
- Avoid automatically retrying a scan after a request is dispatched unless processing is idempotent; show an accessible, visible status-unknown message when the response outcome cannot be established.
- Keep the visible Good and Bad count controls synchronized with authoritative Livewire state after scan-driven morphs while preserving manual editing behavior.
- Harden selected and pending location state so client tampering cannot read or count against a foreign-setting or consignment location.
- Add focused server/component regression coverage and a manual browser checklist for real scanner timing and DOM behavior; do not add or require a full-suite test plan.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-opname-count-drafts`: Strengthen repeated-scan delivery, visible count synchronization, scan failure feedback, and active-setting location enforcement in the stock-opname editor.

## Impact

- Affects `AdjustmentProductTable`, its Livewire Blade view, location-selection handling, and focused Adjustment tests.
- Preserves the existing product/conversion/serial resolution rules, including stock opname's intentional ability to observe existing serials from other recorded locations or statuses.
- Does not change approval-time inventory reconciliation, document persistence schema, or scanner hardware dependencies.
- Requires human browser verification for rapid physical scans, request-failure feedback, focus restoration, and manual Good/Bad editing.
