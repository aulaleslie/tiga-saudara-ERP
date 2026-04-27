## 1. Receipt Data Assembly

- [x] 1.1 Eager-load completed checkout split Sales context needed for bundle composition (`checkoutSales.sale.saleDetails.bundleItems`) in POS receipt data generation.
- [x] 1.2 Build a reusable bundle composition mapper that aggregates component name and customer quantity per displayed parent transaction line without exposing component monetary fields.
- [x] 1.3 Use persisted completed checkout/Sales bundle context before any draft transaction metadata fallback.
- [x] 1.4 Preserve existing non-bundled receipt line data, totals, payment breakdown, and unit breakdown behavior.
- [x] 1.5 Add receipt date metadata that uses completed checkout finalization time for completed transactions and POS transaction creation time for draft/loaded transactions.

## 2. Receipt Template

- [x] 2.1 Render bundle component rows or nested component text beneath parent item names on POS receipts and reprint receipts.
- [x] 2.2 Ensure component display includes only component name and customer quantity, with no price, subtotal, allocation, owner, or split Sales identifiers.
- [x] 2.3 Move `Harga sudah termasuk PPN CV TIGA COMPUTER © 2021` below the dashed tail line and style it smaller than normal line item text.
- [x] 2.4 Remove `Terakhir dicetak oleh ...` / last-printer wording from the customer-facing receipt while keeping receipt print history data available internally.
- [x] 2.5 Render the POS transaction/checkout date-time in the footer area instead of the latest print/reprint timestamp.

## 3. Transaction Detail Display

- [x] 3.1 Load or derive bundle composition context for POS transaction detail pages, including completed split checkout Sales bundle rows.
- [x] 3.2 Render bundle component name and customer quantity beneath each bundled parent row in `transactions/show.blade.php`.
- [x] 3.3 Keep component price, subtotal, allocation, owner, and split Sales identifiers hidden from transaction detail composition display.
- [x] 3.4 Preserve existing transaction detail behavior for non-bundled rows, serial badges, discounts, and totals.

## 4. Print/Reprint Audit Behavior

- [x] 4.1 Keep initial receipt print logging as `PRINT` unchanged.
- [x] 4.2 Keep reprint logging as `REPRINT` unchanged.
- [x] 4.3 Verify print history records remain queryable with count, user, and timestamp even though the receipt no longer renders the last-printer summary.

## 5. Regression Coverage

- [x] 5.1 Add or update receipt coverage for a completed split bundle transaction where the component exists in a non-primary Sales document.
- [x] 5.2 Add or update reprint coverage asserting the receipt hides `Terakhir dicetak oleh` and the reprinting user name while still creating a `REPRINT` log.
- [x] 5.3 Add coverage asserting the receipt footer uses checkout finalization time or transaction creation time instead of latest reprint time.
- [x] 5.4 Add coverage asserting the PPN/company footer text appears below the dashed tail line in very small footer styling.
- [x] 5.5 Add transaction detail coverage asserting bundled component name and quantity are shown without component monetary fields.
- [x] 5.6 Run targeted POS receipt, transaction reprint, transaction detail, and POS bundle split posting tests.
