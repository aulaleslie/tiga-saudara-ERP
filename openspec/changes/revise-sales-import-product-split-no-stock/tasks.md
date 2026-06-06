## 1. Test Coverage

- [x] 1.1 Add sales import tests proving `Tag` no longer affects non-Daizu ownership and is still synced as metadata.
- [x] 1.2 Add sales import tests proving one invoice with `*`, ` TP`, and unmarked rows creates three `DISPATCHED` sales for Tiga Nusa, TOP IT, and Perdana.
- [x] 1.3 Add sales import tests proving duplicate lookup uses the product-name owner and ignores changed CSV tags.
- [x] 1.4 Add sales import tests proving Daizu/Kedelai rows still override product markers and tags.
- [x] 1.5 Add sales import tests proving imported dispatch records and dispatch details are created without product stock, global product quantity, or inventory transaction mutation.
- [x] 1.6 Add sales import tests proving CSV `Status Hari Ini` maps `Lunas`, `Belum Dibayar`/`Belum Lunas`, `Terbayar Sebagian`, and `Lewat Jatuh Tempo` to `PAID`, `UNPAID`, or `PARTIAL`.
- [x] 1.7 Add sales import tests proving CSV `Total` is authoritative and source-total adjustment is allocated across generated owner sales.
- [x] 1.8 Add sales import tests proving PKP owner rows retain tax while non-PKP owner rows suppress CSV tax before totals and payment allocation.

## 2. Owner Resolution

- [x] 2.1 Update `SalesImportService::resolveTenant()` so non-Daizu sales ownership ignores `Tag` and uses product-name markers with `PERDANA` fallback.
- [x] 2.2 Update `SalesImportService::resolveEffectiveOwnerKey()` so sales grouping keys are based on Daizu/Kedelai or product-name marker ownership only.
- [x] 2.3 Update remaining sales import stock/location owner resolution used for dispatch detail placement to ignore `Tag` and historical purchase-owner fallback.
- [x] 2.4 Preserve existing tag collection and `syncTags()` behavior after generated sale creation.

## 3. Settlement And Source Total

- [x] 3.1 Add a sales-compatible payment summary path that applies purchase import current-status semantics while preserving existing money parsing and deduction handling.
- [x] 3.2 Update sales source-invoice processing to use CSV `Total` as authoritative when status mapping is available.
- [x] 3.3 Allocate sales source-total adjustments across owner groups proportionally using the existing document adjustment allocator.
- [x] 3.4 Ensure generated split sales reconcile `total_amount`, `paid_amount`, `due_amount`, cash payment rows, and deduction payment rows per owner and in aggregate.
- [x] 3.5 Keep zero-total generated sales valid with no payment rows.

## 4. Tax Gating

- [x] 4.1 Update sales import group gross-total calculation to use owner PKP-gated tax values.
- [x] 4.2 Update sales import detail creation so non-PKP generated owner sales persist `tax_id = null` and `product_tax_amount = 0`.
- [x] 4.3 Ensure PKP generated owner sales can still resolve and persist CSV tax values.
- [x] 4.4 Ensure sales header `tax_amount`, `tax_percentage`, and `is_tax_included` are calculated from persisted PKP-gated detail values.

## 5. Dispatch Without Inventory Mutation

- [x] 5.1 Refactor sales import dispatch creation so dispatch and dispatch details can be created without stock mutation.
- [x] 5.2 Remove sales import `ProductStock` quantity decrements for future imported sales.
- [x] 5.3 Remove sales import `Product.product_quantity` decrements for future imported sales.
- [x] 5.4 Remove sales import inventory `Transaction` creation for future imported sales.
- [x] 5.5 Keep generated imported sale status as `Sale::STATUS_DISPATCHED`.

## 6. Regression And Verification

- [x] 6.1 Run focused sales import ownership, payment ledger, split-owner allocation, tax, and dispatch tests.
- [x] 6.2 Run related import payment resolver unit tests.
- [x] 6.3 Run a broader focused import test pass covering sales and purchase imports to confirm purchase behavior remains unchanged.
- [x] 6.4 Review generated OpenSpec deltas against implemented behavior and update tasks/specs only if implementation reveals a concrete mismatch.
