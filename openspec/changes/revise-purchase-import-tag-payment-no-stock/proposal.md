## Why

Purchase imports now need to mirror the current Accurate CSV export semantics more closely: tenant ownership is represented by `Tag` except for the explicit Daizu/Kedelai product exception, while product-name markers are only historical naming hints. The current importer still lets markers route ownership and stock, recalculates average cost from imported quantities, and treats some payment mismatches as failures even when the CSV `Total` and `Status Hari Ini` provide the intended document state.

## What Changes

- Keep the existing Daizu/Kedelai product-name override so Kedelai-related purchase rows always belong to the Daizu setting.
- Resolve all non-Daizu purchase ownership from mapped CSV `Tag`; blank or unmapped tags fall back to `PERDANA`.
- Preserve raw CSV tags as purchase metadata even when the tag is blank, unmapped, or not used for owner resolution.
- Continue parsing `*` prefixes, `TP` suffixes, and unmarked product names to normalize product names, but remove product markers from tenant, stock owner, and duplicate-owner routing decisions.
- Create purchases, purchase details, suppliers, products, payment rows, and `last_purchase_price` updates from imports, while keeping imported purchase headers in the existing received status convention.
- Remove purchase-import inventory side effects: no `ProductStock` quantity increments, no global `products.product_quantity` increments, and no `BUY` inventory `transactions` for imported purchase rows.
- Update purchase price synchronization so purchase imports update `last_purchase_price` across settings but leave existing `average_purchase_price` unchanged; new `product_prices` rows default average purchase price to zero.
- Use CSV `Total` as authoritative for purchase-import payment settlement when calculated line totals or payment fields do not add up cleanly.
- Map purchase `Status Hari Ini` values from the import:
  - `Lunas` imports as fully paid.
  - `Belum Dibayar` imports as unpaid.
  - `Terbayar Sebagian` imports as partial.
  - `Lewat Jatuh Tempo` imports as unpaid when there is no payment, or partial when `Pembayaran` is positive; overdue display remains a reporting/aging concern.
- Update user-facing purchase upload guidance and tests so marker fallback is no longer documented as an owner-routing rule.

## Capabilities

### New Capabilities
- `purchase-import-inventory-neutrality`: Purchase imports create purchase documents and product/catalog records without mutating inventory quantities or inventory transaction logs.

### Modified Capabilities
- `purchase-import-daizu-ownership`: Non-Daizu purchase ownership changes from tag-then-marker fallback to tag-then-PERDANA fallback; Daizu/Kedelai product ownership remains product-name based.
- `import-document-total-reconciliation`: Purchase imports use CSV `Total` as the authoritative settlement total for status-based payment resolution.
- `import-payment-ledger-consistency`: Purchase imports resolve paid/due/payment status from imported `Status Hari Ini`, `Total`, `Pembayaran`, `Sisa Tagihan Hari Ini`, and `Jumlah Pemotongan` according to current CSV status labels.
- `import-product-price-sync`: Purchase imports update `last_purchase_price` only and no longer recalculate `average_purchase_price` from imported quantities.
- `import-split-owner-payment-allocation`: Purchase split-owner grouping and allocation are based on Daizu or tag/PERDANA ownership, not product markers, and zero-total purchase groups no longer preserve stock mutation behavior.

## Impact

- Affected code: `Modules/Purchase/Services/PurchaseImportService.php`, `App\Support\ImportPaymentSummaryResolver`, purchase import upload copy, and focused purchase import tests.
- Affected data behavior: future purchase imports stop mutating `product_stocks`, `products.product_quantity`, and inventory `transactions`; existing historical data is not rewritten.
- Affected reports: purchase import no longer stores an overdue-specific payment status; reports and aging views determine overdue display from due dates and balances.
- No database schema change is expected.
