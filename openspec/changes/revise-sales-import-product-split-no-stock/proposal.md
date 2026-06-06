## Why

Sales CSV imports need to represent historical sales documents exactly as accounting records without consuming ERP stock again. Current sales import ownership is tag-driven before product markers, applies tax from CSV rows without owner PKP gating, and decrements stock during auto-dispatch, which no longer matches the intended import model.

## What Changes

- Resolve sales import owner splits from product names only: whole-word Kedelai/Daizu products still route to Daizu Kedelai, `*` rows route to `CV TIGA NUSA COMPUTER`, ` TP` suffix rows route to `CV TOP IT INTERNUSA`, and unmarked rows route to `PERDANA`.
- Preserve CSV `Tag` values as metadata, but remove them from sales import owner routing, duplicate owner checks, owner grouping, stock-owner resolution, and payment split grouping.
- Allow one source sales invoice/header to create multiple imported sales when its rows resolve to different product-name owners; allocate document adjustments, paid amounts, deductions, and due amounts proportionally by owner group.
- Treat CSV `Total` and `Status Hari Ini` as authoritative for sales import settlement in the same style as purchase import status mapping.
- Keep imported sales at `DISPATCHED` status and create dispatch/dispatch-detail records, but stop sales import from decrementing `product_stocks`, decrementing `products.product_quantity`, or creating inventory `transactions`.
- Gate sales import tax by resolved owner PKP status: taxable rows may persist tax only when the resolved owner is PKP; non-PKP owner rows must ignore CSV tax fields and persist non-tax line/header values.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sales-import-daizu-ownership`: Sales import ownership changes from tag-priority effective owner routing to product-name owner routing while preserving Daizu/Kedelai priority and tag metadata.
- `import-split-owner-payment-allocation`: Sales import split-owner invoices continue reconciling at source-invoice scope, but owner groups are product-name based, sales source `Total` becomes authoritative like purchase import, and settlement is allocated across generated sales.
- `import-payment-ledger-consistency`: Sales import payment status resolution adopts purchase import CSV status mapping and source-total settlement behavior.
- `sale-tax-assignment`: Sales import tax persistence is gated by the resolved owner setting's PKP status.

## Impact

- Affected code: `Modules/Sale/Services/SalesImportService.php`, `App\Support\ImportPaymentSummaryResolver`, sales import stage/process jobs only if mapper parity changes, and focused sales import tests.
- Affected records: future imported `sales`, `sale_details`, `sale_payments`, `dispatches`, and `dispatch_details`.
- Inventory effect: future sales imports no longer mutate `product_stocks`, `products.product_quantity`, or `transactions`; historical records are not rewritten.
- No schema changes are expected.
