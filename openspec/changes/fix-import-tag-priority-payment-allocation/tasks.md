## 1. Test Coverage

- [x] 1.1 Add purchase import tests proving mapped `Tag` overrides `*`, ` TP`, and unmarked product markers for non-Daizu rows.
- [x] 1.2 Add sales import tests proving mapped `Tag` overrides `*`, ` TP`, and unmarked product markers for non-Daizu rows.
- [x] 1.3 Add purchase and sales tests proving blank or unmapped tags fall back to product marker ownership while preserving raw tag metadata.
- [x] 1.4 Add purchase and sales tests proving Daizu/kedelai product names still override mapped tags and product markers.
- [x] 1.5 Add purchase and sales tests proving duplicate checks use the effective owner, including changed raw tag with same owner and changed mapped tag with different owner.
- [x] 1.6 Add purchase test coverage for `JL.2008.05975`-style tagged invoices where zero-total unmarked rows remain in the mapped tag owner group and no payment mismatch occurs.
- [x] 1.7 Add sales test coverage for `JL1071`-style blank-tag split-owner invoices where payment is allocated pro-rata across owner documents.
- [x] 1.8 Add partial-payment split-owner tests for purchase and sales imports verifying paid and due totals sum back to the source invoice values.
- [x] 1.9 Add zero-total owner group tests verifying the generated document has zero paid/due amounts, no payment row, and preserved stock/transaction behavior.

## 2. Ownership Resolution

- [x] 2.1 Introduce or update purchase import effective owner resolution to use Daizu product detection, then mapped tag, then marker fallback.
- [x] 2.2 Introduce or update sales import effective owner resolution to use Daizu product detection, then mapped tag, then marker fallback.
- [x] 2.3 Update purchase grouping to group rows by invoice number plus effective owner key instead of invoice plus marker-only owner key.
- [x] 2.4 Update sales grouping to group rows by invoice number plus effective owner key instead of invoice plus marker-only owner key.
- [x] 2.5 Update purchase document owner, stock owner, ProductPrice owner, inventory Transaction owner, and duplicate checks to use the same effective owner.
- [x] 2.6 Update sales document owner, stock owner, dispatch location owner, ProductPrice owner, inventory Transaction owner, and duplicate checks to use the same effective owner.
- [x] 2.7 Ensure unmapped non-empty tags remain synced as metadata and do not block import solely because the tag is unmapped.

## 3. Payment Allocation

- [x] 3.1 Add source-invoice scope payment reconciliation so repeated source `Total`, `Pembayaran`, and outstanding fields are validated once per source invoice.
- [x] 3.2 Calculate each owner group's adjusted document total using the existing line total, document discount, and shipping rules.
- [x] 3.3 Allocate source invoice paid and outstanding amounts pro-rata across positive-total owner groups.
- [x] 3.4 Assign two-decimal rounding remainder deterministically to the largest positive-total owner group.
- [x] 3.5 Allow zero-total owner groups to receive zero paid/due amounts and skip payment row creation.
- [x] 3.6 Ensure purchase payment rows are created only for positive allocated paid amounts and remain reconciled with purchase headers.
- [x] 3.7 Ensure sale payment rows are created only for positive allocated paid amounts and remain reconciled with sale headers.
- [x] 3.8 Ensure source invoice mismatch invalidates all groups for that invoice without creating documents, payments, stock, dispatch, receipt, transaction, or price records.

## 4. Verification

- [x] 4.1 Run focused purchase import payment and ownership tests.
- [x] 4.2 Run focused sales import payment and ownership tests.
- [x] 4.3 Run existing import document adjustment tests to confirm discount and shipping reconciliation still passes.
- [x] 4.4 Run `php artisan test` with focused filters for import-related regression coverage, or `composer test:fresh-sqlite` if practical.

## 5. Feedback: Allocate document-level discount/shipping across split owners

- [x] 5.1 Add `App\Support\ImportDocumentAdjustmentAllocator` that allocates a single source-invoice document amount pro-rata across owner groups by gross line total, with rounding remainder to the largest positive group.
- [x] 5.2 Resolve document `Diskon`/`Biaya Pengiriman` once at source-invoice scope in purchase and sales `processSourceInvoice`, allocate per group, and build each group's adjusted total as gross minus allocated discount plus allocated shipping.
- [x] 5.3 Persist each owner document's `discount_amount`/`shipping_amount` from its allocated share instead of the full repeated document value.
- [x] 5.4 Add purchase and sales regression tests proving a two-owner invoice with a repeated document discount reconciles to the valid source total and persisted header discounts sum back to the source discount.
- [x] 5.5 Add unit tests for the document adjustment allocator (zero amount, single positive group, even/uneven split, rounding remainder).
