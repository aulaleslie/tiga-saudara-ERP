## 1. Fix receipt line total (÷100) in PosReceiptService

- [x] 1.1 In `Modules/Pos/Services/PosReceiptService.php` `getReceiptData()`, remove the `/ 100` on `line_meta['line_total']` so `$lineGross` uses the persisted Rupiah value; keep the `qty * unit_price` fallback branch unchanged.
- [x] 1.2 Apply the same fix in `getTransactionReceiptData()` for the draft/loaded-transaction path.
- [x] 1.3 Confirm `buildPackedUnitBreakdown()` retains its `/ 100` on `box_price_applied` / `loose_price_applied` (those remain minor units) and is not touched.
- [x] 1.4 Trace any other consumer of `line_meta['line_total']` in the receipt/print paths to ensure none reintroduces a `/100` mismatch.

## 2. Fix qty-approval leak from price override in sell.blade.php

- [x] 2.1 In `Modules/Pos/Resources/views/sell.blade.php`, change the `clientPendingApprovals` store shape to be scoped by action type per line (e.g. `clientPendingApprovals[lineId] = { QTY_REDUCE: {...}, PRICE_OVERRIDE: {...} }`); update the declaration comment.
- [x] 2.2 Update the QTY_REDUCE submit handler to write only the `QTY_REDUCE` action key.
- [x] 2.3 Update the PRICE_OVERRIDE submit handler to write only the `PRICE_OVERRIDE` action key.
- [x] 2.4 Update both qty-cell renderers (serial and non-serial rows) so `clientPending` reads only `clientPendingApprovals[lineId]?.QTY_REDUCE` for the qty-reduce fallback.
- [x] 2.5 Update every `delete clientPendingApprovals[lineId]` clear path to remove the correct action-scoped key(s) without discarding unrelated actions on the same line.

## 3. Tests

- [x] 3.1 Add/extend a POS receipt test asserting a qty-1, Rp335.000 line prints Rp335.000 in the line total column (not Rp3.350) for a completed checkout.
- [x] 3.2 Add a receipt test for a packed line asserting both the correct Rupiah per-unit breakdown and the correct Rupiah line total.
- [x] 3.3 Add a supervised-cart-action test asserting a pending/approved PRICE_OVERRIDE on a line leaves that line's quantity − control unchanged (no "Periksa").
- [x] 3.4 Add a test asserting coexisting QTY_REDUCE and PRICE_OVERRIDE requests on one line render independent slot states.

## 4. Verification

- [x] 4.1 Run focused POS tests (`php artisan test` with a receipt/cart filter, or `composer test:fresh-sqlite`).
- [x] 4.2 Manually verify a printed struk shows matching per-line total and grand total, and that requesting a price override does not flip the qty − button to "Periksa".
- [x] 4.3 Run `openspec validate fix-pos-receipt-subtotal-and-qty-approval-leak` and confirm the change is valid.
