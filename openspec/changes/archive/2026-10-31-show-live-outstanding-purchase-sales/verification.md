# Verification Results: Live Outstanding Purchase and Sales

This document records the exact test commands and verification results for the `show-live-outstanding-purchase-sales` change.

## Task 2.3 Settlement Query Count Boundedness Test

Command:
```bash
php artisan test --filter=SettlementQueryCountBoundednessTest
```

Result:
```text
PASS  Tests\Feature\SettlementQueryCountBoundednessTest
✓ purchase table settlement queries remain bounded as page size increases (18.80s)
✓ sale table settlement queries remain bounded as page size increases (0.23s)

Tests: 2 passed (2 assertions)
```

Verified that settlement-related queries (hitting `purchase_payments`, `sale_payments`, and `sale_payment_credit_applications`) do not scale with the number of rows displayed per page. For both PurchaseTable and SaleTable, query counts remain identical whether rendering 2 rows or 8 rows per page.

## Tasks 3.1 & 3.2 Document Detail Outstanding Balance Tests

Command:
```bash
php artisan test --filter=DocumentDetailOutstandingBalanceTest
```

Result:
```text
PASS  Tests\Feature\DocumentDetailOutstandingBalanceTest
✓ purchase detail displays sisa pembayaran with live balance without payment create permission (18.67s)
✓ global purchase detail displays sisa pembayaran and preserves authorized cross setting access (0.16s)
✓ sales detail displays sisa tagihan with live balance without payment create permission (0.18s)
✓ global sales detail displays sisa tagihan and preserves authorized cross setting access (0.19s)
✓ detail viewing does not mutate or reconcile stored records (0.18s)
✓ unauthorized normal cross business access remains rejected (0.15s)

Tests: 6 passed (24 assertions)
```

Verified that:
- Purchase detail displays "Sisa Pembayaran" with formatted live balance immediately under "Total Keseluruhan" without mutating stored records.
- Sales detail displays "Sisa Tagihan" with formatted live balance immediately under "Total Keseluruhan" without mutating stored records.
- Zero outstanding amounts are explicitly formatted (Rp 0 / RP. 0.00).
- Normal cross-business access without authorization is rejected (404 via setting isolation guard).
- Authorized cross-business access via global payment routes functions cleanly.

## Tasks 4.1 & 4.2 Focused Integration & Regression Verification

### Existing Global Table, Filter, Order, and Isolation Regressions

1. **GlobalSalePaymentFiltersTest**
   - Command: `php artisan test --filter=GlobalSalePaymentFiltersTest`
   - Result: 38 passed (130 assertions)
   - Verifies global sale filters (business, document date, due date, multi-business, date range reversal, card filter compose).

2. **GlobalPurchasePaymentFiltersTest**
   - Command: `php artisan test --filter=GlobalPurchasePaymentFiltersTest`
   - Result: 34 passed (125 assertions)
   - Verifies global purchase filters (business, document date, due date, card filter compose, query string persistence).

3. **EmbeddedGlobalPurchasePaymentIsolationTest**
   - Command: `php artisan test --filter=EmbeddedGlobalPurchasePaymentIsolationTest`
   - Result: 7 passed (18 assertions)
   - Verifies supplier isolation, parameter tampering rejection, and workspace scoping.

4. **EmbeddedGlobalSalePaymentIsolationTest**
   - Command: `php artisan test --filter=EmbeddedGlobalSalePaymentIsolationTest`
   - Result: 7 passed (18 assertions)
   - Verifies customer isolation, parameter tampering rejection, and workspace scoping.

5. **SalePaymentInvalidationTest & PurchasePaymentInvalidationAuthTest**
   - Commands:
     - `php artisan test --filter=SalePaymentInvalidationTest` (2 passed, 26 assertions)
     - `php artisan test --filter=PurchasePaymentInvalidationAuthTest` (7 passed, 9 assertions)
   - Verifies that settlement invalidation logic and authorization policies remain completely unaffected and preserved.

### Manual / Browser Verification Notes
- Detail labels ("Sisa Pembayaran" on purchase detail, "Sisa Tagihan" on sales detail) and list/detail amount parity were checked through automated feature tests simulating full blade render pipelines with both positive and zero live balance values.
- Headless test environment cannot launch live interactive browser UI; browser visual rendering is verified indirectly via HTML assertion of formatters (`formatRupiah` and `format_currency`), table rows, and detail card markup.
