## Why

Purchase and sales CSV imports now create payment ledger rows and strictly reconcile imported `Total`, `Pembayaran`, and outstanding balances. That exposed a mismatch in split-owner invoices: source payment fields are invoice-level, while current grouping can split one invoice into multiple owner documents using product markers, causing valid historical rows to be rejected.

The ownership rule also needs to reflect the intended source semantics: Daizu/kedelai products always belong to Daizu, but for non-Daizu rows a mapped CSV `Tag` is the primary owner signal and product markers are only a fallback when the tag is blank or unmapped.

## What Changes

- Change purchase and sales import ownership priority to Daizu product detection first, mapped CSV `Tag` second, and product marker fallback third.
- Preserve raw CSV tags as metadata even when the tag is unmapped and marker fallback is used.
- Group purchase and sales import rows by invoice plus the new effective owner key.
- Reconcile invoice-level payment totals across split owner documents by allocating paid and outstanding amounts pro-rata by each owner document total.
- Allow zero-total owner document groups, including zero-priced bundle/component rows, and create no payment row for those groups.
- Keep payment ledger creation, document header balances, stock owner, price owner, dispatch/receipt locations, and inventory transactions aligned to the resolved owner.

## Capabilities

### New Capabilities
- `import-split-owner-payment-allocation`: Defines how purchase and sales imports reconcile invoice-level payment fields when one source invoice is split into multiple owner documents.

### Modified Capabilities
- `purchase-import-daizu-ownership`: Purchase import ownership changes from product-name-only routing to Daizu-first, mapped-tag-second, marker-fallback routing.
- `sales-import-daizu-ownership`: Sales import ownership changes from product-name-only routing to Daizu-first, mapped-tag-second, marker-fallback routing.

## Impact

- Affected code: `Modules/Purchase/Services/PurchaseImportService.php`, `Modules/Sale/Services/SalesImportService.php`, import payment summary/allocation helpers if introduced, and focused import tests.
- Affected data behavior: future imported purchases and sales may resolve to different owners than the current product-marker-only rule when a mapped CSV tag is present.
- Affected accounting behavior: future split-owner imports create payment rows allocated per generated owner document instead of validating each split group against the full source invoice total.
- No schema migration or historical data backfill is planned.
