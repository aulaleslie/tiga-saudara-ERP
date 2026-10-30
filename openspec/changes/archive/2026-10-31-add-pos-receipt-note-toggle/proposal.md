# Proposal

## Why

Cashiers need to choose whether Catatan Transaksi appears on an individual POS receipt without changing the business-wide receipt setting. Receipt pages currently provide Cetak Struk but no local override.

## What Changes

- Add a labeled Tampilkan Catatan Transaksi checkbox beside Cetak Struk on POS receipt pages.
- Initialize it from the receipt business's current note visibility setting, then immediately reflect local changes in both screen preview and print output.
- Keep the override within the open page; newly opened or reloaded receipts follow the setting again.
- Cover the shared checkout, transaction, reprint, historical, draft/loaded and global-payment receipt template, plus the separate Kas Bon collection receipt using its linked POS transaction note.
- Preserve safe note formatting, empty-note omission, other receipt switches, financial behavior and existing print audit semantics.
- Use focused automated receipt checks and a short browser/print-preview verification.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Allow a page-local note visibility choice and cover Kas Bon collection receipt notes.
- `pos-receipt-visibility-settings`: Treat the persisted note switch as the initial receipt-page default, with an ephemeral override.

## Impact

Primarily `Modules/Pos/Resources/views/receipt.blade.php` and `Modules/Pos/Resources/views/receipts/kas-bon-collection.blade.php`, with a small shared Blade control/script if useful. Kas Bon controller/view data may need its receipt-business visibility and linked transaction note resolved explicitly. Focused coverage belongs in existing POS receipt visibility and Kas Bon receipt tests. No migration, new setting, dependency, or persistence endpoint is required.
