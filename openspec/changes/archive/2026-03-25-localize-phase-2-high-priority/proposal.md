## Why

Phase 1 (critical services and auth) is complete. Phase 2 addresses HIGH priority strings that directly impact user experience: authorization error messages in POS controllers, form validation messages, Livewire component flash messages, and POS session validation. These strings are shown to users during normal workflows (cart operations, product table interactions, session management). Completing Phase 2 ensures consistent Indonesian UX across all user-facing operations.

## What Changes

- Replace 6 authorization/abort messages in `PosSellController.php` and `PosSessionController.php` with Indonesian equivalents
- Replace 4 Livewire component flash messages in `ProductTable.php` components (Barcode, Transfer, Adjustment modules) with Indonesian equivalents
- Replace 1 validation request message in `StorePosSessionCloseRequest.php` with Indonesian equivalent
- Total: 11 user-facing strings converted to Bahasa Indonesia
- No code structure changes; direct string replacement only

## Capabilities

### New Capabilities

None - this is localization work that modifies existing message strings, not new features.

### Modified Capabilities

- `localization-english-to-indonesian`: Extending the localization effort from Phase 1 (critical services) to Phase 2 (high-priority user-facing messages)

## Impact

- **Files Modified**: 6 files across POS module and Livewire components
  - `/Modules/Pos/Http/Controllers/PosSellController.php`
  - `/Modules/Pos/Http/Controllers/PosSessionController.php`
  - `/Modules/Pos/Http/Requests/StorePosSessionCloseRequest.php`
  - `/app/Livewire/Barcode/ProductTable.php`
  - `/app/Livewire/Transfer/TransferProductTable.php`
  - `/app/Livewire/Adjustment/ProductTable.php`

- **User Impact**: Authorization errors, validation failures, and product table operations will display Indonesian messages instead of English
- **Testing Impact**: Existing tests may reference English strings; need to verify/update test assertions
- **No API Breaking Changes**: This is string-only replacement; no signature or behavioral changes
