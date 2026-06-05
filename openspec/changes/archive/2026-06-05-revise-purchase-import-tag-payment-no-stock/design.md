## Context

`Modules\Purchase\Services\PurchaseImportService` currently treats purchase import product markers as part of effective-owner resolution after Daizu and mapped CSV `Tag`. The same service also increments `ProductStock`, increments global `products.product_quantity`, creates inventory `Transaction` rows, and recalculates weighted average purchase prices from imported quantities. This worked when purchase imports were treated as inventory receipts, but the current import source is an accounting/document import where stock is maintained separately from warehouse stock CSV data.

The Accurate purchase CSV exports include `Status Hari Ini`, `Total`, `Pembayaran`, `Sisa Tagihan`, `Sisa Tagihan Hari Ini`, and `Jumlah Pemotongan`. The 2026 S1 purchase data contains `Lunas`, `Belum Dibayar`, `Terbayar Sebagian`, and `Lewat Jatuh Tempo`; `Lewat Jatuh Tempo` is an aging/reporting state rather than an ERP payment status.

## Goals / Non-Goals

**Goals:**
- Make non-Daizu purchase import ownership depend only on the explicit owner-routing tags `cv tiga nusa` and `cv top it`, with every other tag value falling back to `PERDANA`.
- Preserve the Daizu/Kedelai product-name exception above tag routing.
- Keep marker parsing for product-name normalization only.
- Keep future purchase imports document-oriented: create purchases, details, products, tags, payment rows, and last purchase prices without inventory quantity side effects.
- Resolve purchase import payments from CSV `Status Hari Ini` and CSV `Total` so imported payment status matches source data.
- Preserve current received purchase header status to avoid broad report/UI churn.

**Non-Goals:**
- Do not rewrite historical purchases, product stocks, inventory transactions, or product prices.
- Do not change sales import behavior except where shared payment resolver code must remain compatible.
- Do not import warehouse stock quantities in this change; `upload-data/warehouse_stock_quantity.csv` remains separate input for a future stock import/reconciliation flow.
- Do not add a new database column or migration unless implementation discovers an unavoidable schema constraint.

## Decisions

### Owner Resolution

Purchase owner resolution will become:

```text
Daizu/Kedelai product name
  -> DAIZU setting
else Tag is cv tiga nusa
  -> CV TIGA NUSA COMPUTER setting
else Tag is cv top it
  -> CV TOP IT INTERNUSA setting
else
  -> PERDANA setting
```

Only `cv tiga nusa` and `cv top it` are purchase owner-routing CSV tags. Other historical or internal labels such as `aries`, `rahmat`, `agus`, `perdana`, blank tags, and unknown tags remain raw purchase metadata but must not route ownership; they fall back to `PERDANA` for non-Daizu rows. Product markers remain parsed by `parseProductName()` so `* Product`, `Product TP`, and unmarked names still normalize to the intended product name. `resolveEffectiveOwnerKey()`, `resolveTenant()`, duplicate checks, source-invoice owner grouping, and any remaining stock-owner path must no longer use marker fallback or non-owner-routing tag fallback for non-Daizu rows.

Alternative considered: keep all historical tag labels as owner-routing mappings. Rejected because purchase imports should only honor the two explicit external owner tags; all other tag labels are metadata and should not spread purchases across owners.

Alternative considered: reject blank, unknown, or non-owner-routing tags. Rejected because the agreed import behavior is to keep importing these rows under `PERDANA` while retaining raw tag metadata for audit/search.

### Inventory-Neutral Purchase Imports

The import service will create purchase details but skip the stock mutation block for imported purchase rows. That means no `ProductStock::firstOrCreate()` for quantity receipt, no `ProductStock` increments, no `Product::increment('product_quantity')`, and no inventory `Transaction::create()` with type `BUY`.

Alternative considered: keep stock updates but route them through tag/PERDANA. Rejected because current stock quantities are managed separately and import should no longer be a stock source of truth.

### Product Price Synchronization

Future purchase imports will still upsert `product_prices` across settings and update `last_purchase_price` from each imported final tax-included unit price. Existing `average_purchase_price` values will be preserved. New `product_prices` rows created by the import will use `average_purchase_price = 0`.

Alternative considered: continue weighted average calculation without stock increments. Rejected because the denominator would depend on stale inventory quantities while imported quantity is no longer an inventory receipt.

### Payment Resolution

Purchase imports will treat CSV `Total` as the authoritative settlement total for payment resolution. The importer should still calculate and persist purchase line/header totals from imported rows, discounts, shipping, and taxes, but payment paid/due/status should be derived from source `Total` and `Status Hari Ini` when the source settlement fields do not add up cleanly.

Status mapping:

```text
Lunas              -> PAID, paid = Total, due = 0
Belum Dibayar      -> UNPAID, paid = 0, due = Total
Terbayar Sebagian  -> PARTIAL, paid = Pembayaran, due = Total - paid - deduction
Lewat Jatuh Tempo  -> PARTIAL if Pembayaran > 0, otherwise UNPAID
```

`Sisa Tagihan Hari Ini` remains the preferred outstanding value when it reconciles with `Total`; otherwise the importer derives the outstanding amount from source `Total`, resolved cash payment, and `Jumlah Pemotongan`. Deduction still creates/uses the non-cash settlement credit path so reports that sum active payment rows see the same paid amount as the purchase header.

Alternative considered: keep strict source-total rejection for purchase imports. Rejected because the user-provided purchase CSVs use source `Total` and current status as the authoritative document state, including historical/stale payment field combinations.

### Received Header Status

Imported purchase headers will continue using the existing received purchase status even though stock is not mutated by this import. This keeps the document available in current purchase flows and avoids introducing a new lifecycle state in a behavior-only import revision.

Alternative considered: import as pending/ordered. Rejected for this change because it may change purchase list/report behavior beyond the requested import semantics.

## Risks / Trade-offs

- [Risk] Existing tests and specs expect marker fallback and stock mutation. -> Update tests and delta specs to assert the new tag/PERDANA and inventory-neutral behavior explicitly.
- [Risk] Shared `ImportPaymentSummaryResolver` is also used by sales imports. -> Add purchase-specific status/CSV-total behavior behind a dedicated method or mode rather than loosening sales import reconciliation.
- [Risk] Purchase header totals and payment totals may diverge if CSV `Total` is authoritative and calculated line totals drift materially. -> Keep line details from calculated rows, then reconcile generated purchase header totals to CSV `Total` through document-level adjustment before allocating paid/due.
- [Risk] Reports may have assumed imported purchase `RECEIVED` means stock was incremented. -> No schema migration or historical rewrite; this change only affects future purchase imports and should be documented in upload guidance.
- [Risk] Non-owner-routing tags falling back to `PERDANA` can hide source data issues. -> Preserve raw tags as metadata so imports remain auditable and searchable.
