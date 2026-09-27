## Why

Completed POS bundle checkouts can split revenue across multiple generated Sales documents, but the customer receipt and POS transaction detail still render only the parent transaction line. This hides the bundle component that the customer should receive and also exposes reprint audit wording as customer-facing receipt content.

## What Changes

- Show bundle component information under the parent bundled item on POS receipts and reprint receipts, including component name and customer quantity only.
- Keep bundle component price, subtotal, allocation amount, source owner, and split Sales details hidden from customer-facing receipt lines.
- Show bundle component information on the POS transaction detail page for completed, draft, and loaded POS transactions when bundle context is available.
- Move the footer notice `Harga sudah termasuk PPN CV TIGA COMPUTER © 2021` below the receipt dash line and render it in a very small font.
- Keep print/reprint tracking logs intact, but remove `Terakhir dicetak oleh ...` wording from the printed receipt.
- Display the POS transaction date/time on the receipt footer instead of the latest print/reprint timestamp.

## Capabilities

### New Capabilities
- `pos-transaction-detail-bundle-display`: POS transaction detail pages show bundle composition context beneath bundled parent rows without component pricing.

### Modified Capabilities
- `pos-professional-receipt`: POS receipt layout includes customer-facing bundle composition rows and updated footer placement/typography.
- `pos-transaction-reprint`: Reprint receipts preserve audit logging while avoiding reprint user/timestamp wording in customer-facing receipt content.
- `receipt-print-history`: Print history remains tracked for audit purposes but no longer requires showing the last printer summary on the printed receipt.

## Impact

- Affected views: `Modules/Pos/Resources/views/receipt.blade.php` and `Modules/Pos/Resources/views/transactions/show.blade.php`.
- Affected services/controllers: POS receipt data assembly in `Modules/Pos/Services/PosReceiptService.php` and transaction detail eager-loading in `Modules/Pos/Http/Controllers/PosTransactionController.php`.
- Affected data sources: `pos_transaction_lines.line_meta`, `pos_checkouts.checkoutSales.sale.saleDetails.bundleItems`, `sale_bundle_items`, and existing receipt print logs.
- Regression coverage should include split bundle checkout receipts, reprint receipts, receipt footer text/date behavior, print log persistence, and transaction detail bundle display.
