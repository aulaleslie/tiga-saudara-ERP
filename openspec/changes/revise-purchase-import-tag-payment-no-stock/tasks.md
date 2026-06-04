## 1. Focused Test Coverage

- [x] 1.1 Update purchase import ownership tests so mapped tags still route to mapped owners while `*`, `TP`, and unmarked product markers only normalize product names.
- [x] 1.2 Add purchase import tests for blank and unmapped tags falling back to `PERDANA` while preserving raw tag metadata on the purchase.
- [x] 1.3 Add/adjust Daizu purchase import tests proving Kedelai/Daizu product names still route to the Daizu setting before tag/default routing.
- [x] 1.4 Add purchase import inventory-neutrality tests asserting successful imports create purchases/details/products but do not increment `ProductStock`, do not increment `products.product_quantity`, and do not create `BUY` inventory transactions.
- [x] 1.5 Add purchase price sync tests asserting `last_purchase_price` updates across settings while existing `average_purchase_price` remains unchanged and new rows default average to zero.
- [x] 1.6 Add purchase payment status tests for `Lunas`, `Belum Dibayar`, `Terbayar Sebagian`, `Lewat Jatuh Tempo` with payment, and `Lewat Jatuh Tempo` without payment using CSV `Total` as authoritative settlement total.
- [x] 1.7 Update split-owner purchase import tests so grouping uses Daizu/tag/PERDANA owner keys and zero-total purchase groups keep document/catalog behavior without stock mutation.

## 2. Owner and Product Normalization

- [x] 2.1 Revise `PurchaseImportService::resolveTenant()` so non-Daizu rows resolve mapped tags first and otherwise fall back to the `PERDANA` setting.
- [x] 2.2 Revise `PurchaseImportService::resolveEffectiveOwnerKey()` so non-Daizu grouping keys use mapped tag owner or `PERDANA`, never product marker fallback.
- [x] 2.3 Remove or isolate marker-based owner helpers so `parseProductName()` remains available only for product-name cleanup.
- [x] 2.4 Ensure duplicate purchase matching uses invoice number plus the revised effective owner, including `PERDANA` fallback for blank/unmapped tags.
- [x] 2.5 Keep raw tag syncing for every non-empty CSV tag, including unmapped tags.

## 3. Inventory-Neutral Purchase Import

- [x] 3.1 Remove purchase-import stock mutation from the import detail loop: no `ProductStock` creation for quantity receipt and no stock bucket increments.
- [x] 3.2 Remove purchase-import global product quantity increments.
- [x] 3.3 Remove purchase-import `BUY` inventory transaction creation.
- [x] 3.4 Preserve purchase creation, purchase detail creation, product creation, supplier creation, tax handling, and received purchase header status.
- [x] 3.5 Remove now-unused stock-owner/location resolution imports and helper paths when they are no longer referenced.

## 4. Purchase Price Sync

- [x] 4.1 Replace weighted-average purchase price recalculation for purchase imports with preservation of existing `average_purchase_price`.
- [x] 4.2 Update purchase price upsert logic so existing rows update only `last_purchase_price` and `updated_at` while preserving sales prices and average purchase price.
- [x] 4.3 Ensure newly created `product_prices` rows from purchase import set `last_purchase_price` to the imported final tax-included unit price and `average_purchase_price` to zero.
- [x] 4.4 Preserve duplicate-skipped behavior so skipped purchase invoices do not update any purchase price fields.

## 5. Payment Resolution

- [x] 5.1 Add a purchase-specific payment summary resolution path or mode that accepts CSV `Status Hari Ini` and source `Total` without loosening sales import reconciliation.
- [x] 5.2 Implement `Lunas` purchase import settlement as `PAID`, paid equal to CSV `Total`, and due equal to zero, including payment rows that sum to paid amount.
- [x] 5.3 Implement `Belum Dibayar` purchase import settlement as `UNPAID`, paid zero, due equal to CSV `Total`, and no cash payment row.
- [x] 5.4 Implement `Terbayar Sebagian` purchase import settlement as `PARTIAL`, using `Pembayaran`, `Jumlah Pemotongan`, and preferred outstanding fields when they reconcile, otherwise deriving due from CSV `Total`.
- [x] 5.5 Implement `Lewat Jatuh Tempo` purchase import settlement as `PARTIAL` when `Pembayaran` is positive and `UNPAID` when payment is blank or zero; leave overdue display to report/aging logic.
- [x] 5.6 Preserve existing deduction payment method behavior so `Jumlah Pemotongan` remains represented as a non-cash settlement credit when present.
- [x] 5.7 Ensure split-owner payment allocation receives the revised purchase source settlement totals and still allocates cash, deduction, and due without negative due amounts.

## 6. User-Facing Import Guidance

- [x] 6.1 Update purchase upload page guidance to document tag mapping, Daizu/Kedelai exception, and `PERDANA` fallback for blank/unmapped tags.
- [x] 6.2 Remove documentation that product markers route purchase ownership; document markers only as product-name normalization rules.
- [x] 6.3 Update purchase import template guidance if it references marker-based ownership or missing current CSV columns.

## 7. Verification

- [x] 7.1 Run focused purchase import ownership/payment/price/inventory tests.
- [x] 7.2 Run focused shared import payment resolver tests to confirm sales import behavior was not loosened.
- [x] 7.3 Run `openspec status --change revise-purchase-import-tag-payment-no-stock` and confirm apply-required artifacts remain complete.
- [x] 7.4 If focused tests pass, run broader purchase import related tests or `php artisan test` with appropriate filters before marking implementation complete.
