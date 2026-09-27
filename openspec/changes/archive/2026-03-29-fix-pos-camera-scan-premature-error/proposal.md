## Why

POS camera scanning currently shows a generic decode failure (`Gagal memproses barcode. Silahkan coba lagi.`) immediately after camera open in some environments, even when no barcode has been scanned yet. This breaks cashier trust, obscures root cause, and can block normal scan flow due to unstable ZXing/runtime integration behavior.

Manual mobile validation on Samsung Galaxy A55 (2026-03-29) revealed an additional runtime failure mode: scanner status remains `Memindai...` indefinitely and no decode callback path executes, followed by `Video stream has ended before any code could be detected.` when modal closes. This indicates current decode wiring is still mismatched with the selected ZXing browser API contract.

## What Changes

- Harden POS camera scanner initialization so decode-failure messaging is only shown after an actual scan processing attempt, not just camera open/start.
- Add diagnostic debug context alongside the existing decode-failure message so support and developers can identify failure stage quickly.
- Align ZXing integration with a browser-safe load strategy and compatible decode API usage to avoid premature runtime exceptions.
- Restore resolver parity for camera-triggered scan flow so decoded values use the same shared resolver contract as Enter/helper triggers.
- Add regression coverage and manual validation notes for camera-open idle state, first-scan handling, and failure observability.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-scan-input-actions`: Tighten camera-scan runtime behavior so camera open remains non-error idle until a real scan attempt, and decode failures expose actionable debug diagnostics while preserving existing cashier guidance.

## Impact

- Affected frontend scanner runtime in `public/js/pos-camera-scanner.js`.
- Affected POS sell script wiring in `Modules/Pos/Resources/views/sell.blade.php` (scanner dependency load/interop and shared resolver exposure).
- Affected POS scan/camera UI test coverage in `Modules/Pos/Tests/Feature/POSSellShellScanUiTest.php` and/or related frontend behavior checks.
- No backend endpoint contract changes (`/pos/sell/search/resolve` remains unchanged).
