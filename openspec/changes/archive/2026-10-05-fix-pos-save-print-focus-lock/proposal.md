# Proposal

## Why

**Simpan dan Cetak** opens a receipt tab that automatically calls `window.print()` on load. Cashiers may press Ctrl+W while Chrome's print preview is open, leaving the linked POS tab unable to accept focus until reload. Separately, **Cari Produk** does not focus its keyword input on open because Bootstrap 4 emits `shown.bs.modal` through jQuery while its handlers were native listeners.

## What Changes

- **Simpan dan Cetak** continues opening the saved draft receipt in a new tab, consistent with checkout receipt and **Simpan Draft → Cetak**. It no longer opens the print dialog automatically; the cashier clicks **Cetak Struk** on the receipt page.
- **Cari Produk** focuses its keyword input when opened, including when results are preserved. One delegated result-card keyboard handler prevents duplicate adds after reopening.

## Capabilities

### New Capabilities
(none)

### Modified Capabilities
- `pos-current-transaction-print`: the dedicated current-transaction receipt opens in a new tab without automatic printing.
- `pos-product-search-lifecycle`: opening **Cari Produk** focuses the keyword input, and Enter on a preserved result card adds once.

## Impact

- `Modules/Pos/Resources/views/sell.blade.php`: preserve the new-tab save-and-print handler and fix Cari Produk modal and keyboard bindings.
- `Modules/Pos/Http/Controllers/PosTransactionController.php`: stop passing `autoPrint` from `printCurrentReceipt` while retaining its route, permissions, and active-cart checks.
- `Modules/Pos/Resources/views/receipt.blade.php`: restore the original optional `autoPrint` block; this route does not enable it.
- No database, permission, or API changes.
