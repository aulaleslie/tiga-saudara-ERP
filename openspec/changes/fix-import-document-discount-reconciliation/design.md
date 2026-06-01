## Context

Purchase and sales CSV exports are line-oriented: invoice-level fields such as `Total`, `Pembayaran`, `Sisa Tagihan`, `Diskon`, and `Biaya Pengiriman` are repeated on every product row. The import payment work added strict reconciliation between source `Total`, resolved paid amount, outstanding balance, and the calculated document total. That reconciliation is correct, but the current import total excludes document-level discount and shipping, so valid discounted invoices are marked invalid.

Existing manual purchase and sales create flows support both percentage and fixed global discounts. Their normalizers calculate the final document total as line subtotal total minus computed global discount plus shipping. For imports, the source CSV already provides an exact fixed rupiah discount amount in `Diskon`; the accompanying `Diskon %` is rounded and can drift from `Total`.

## Goals / Non-Goals

**Goals:**

- Make purchase and sales imports reconcile valid invoices with document-level `Diskon` and `Biaya Pengiriman`.
- Match the existing create-flow document math by treating import `Diskon` as a fixed global discount amount.
- Keep payment rows, document `paid_amount`, `due_amount`, and `payment_status` consistent with the adjusted document total.
- Validate repeated document-level fields at invoice and owner group scope before creating documents.
- Preserve existing line discount behavior for `Diskon Per Baris %`.

**Non-Goals:**

- Do not backfill or mutate historical imported purchases, sales, or payment rows.
- Do not change manual purchase or sales create/edit behavior.
- Do not introduce a new database column for raw CSV `Diskon %`.
- Do not reinterpret document `Diskon` as per-line/product discount.
- Do not relax payment reconciliation for genuinely inconsistent source data.

## Decisions

### Use `Diskon` Amount as the Authoritative Import Discount

The importer will resolve document discount from the CSV `Diskon` column and store it as `discount_amount`. Imported documents will set `discount_percentage` to zero.

Rationale:

- The CSV `Diskon` amount reconciles directly with source `Total`.
- `Diskon %` is rounded/display-oriented and can produce small but blocking mismatches.
- Existing create flows already support the fixed global discount path.

Alternative considered: derive the discount from `Diskon %`. This was rejected because invoices such as `322000 * 4.66%` drift from the exact `15000` source discount.

### Apply Repeated Adjustment Fields Once per Invoice Owner Group

For each invoice and owner group, the importer will collect distinct non-blank money values for document `Diskon` and `Biaya Pengiriman`. If a field has more than one distinct value within the group, the group fails. Otherwise, the resolved value is applied once.

Rationale:

- Existing CSV samples repeat the same `Diskon` value on each row for discounted invoices.
- Summing repeated values would over-discount multi-line invoices.
- Failing conflicting values avoids silently guessing when source data is ambiguous.

Alternative considered: take the first value. This was rejected because it would hide malformed imports and could create documents that do not match source totals.

### Reconcile Payment Against Adjusted Document Total

The services will compute:

```text
adjusted_document_total = calculated_line_total - document_discount_amount + document_shipping_amount
```

That adjusted total will be passed to `ImportPaymentSummaryResolver::resolve()` and persisted as `total_amount`.

Rationale:

- Existing payment resolution remains the central place for `Pembayaran`, preferred outstanding balance, and source `Total` validation.
- The resolver should receive the real document total, not a pre-discount/pre-shipping line sum.
- This keeps generated `purchase_payments` and `sale_payments` aligned with persisted headers.

### Keep Line Discount Handling Separate

Purchase import will continue to use `Diskon Per Baris %` as its existing line-level discount input. Document `Diskon` will not populate detail `product_discount_amount`.

Rationale:

- The CSV has separate line and document discount concepts.
- The scanned import data did not show document `Diskon` varying by item row.
- Detail rows should continue reflecting item-level price/tax semantics while the header carries global discount.

## Risks / Trade-offs

- Floating point noise in historical exports → Use existing money parsing and a small monetary tolerance when comparing totals.
- Invoices split by owner marker may repeat an invoice-level discount on rows that are split into more than one ERP document → Validate and apply discount at the same invoice and owner group scope used by the current importer; do not introduce cross-owner allocation in this change.
- Source rows with conflicting repeated `Diskon` or `Biaya Pengiriman` values will fail instead of importing partially → Error messages should identify the repeated adjustment conflict so operators can fix the CSV.
- Persisting `discount_percentage = 0` loses the display percent from the CSV → This is intentional because the amount is authoritative for accounting math; raw CSV remains available on staged import rows for audit during import review.

## Migration Plan

1. Add focused tests for the known failing purchase and sales invoices.
2. Extend purchase and sales import row mapping/staging to preserve `Diskon` and, if needed, `Diskon %` without using the percent in calculations.
3. Add shared or duplicated helper logic to resolve one document-level money value per invoice group for `Diskon` and `Biaya Pengiriman`.
4. Update purchase and sales import services to calculate adjusted document totals and persist header discount/shipping values.
5. Keep payment creation inside the existing import transaction so invalid groups create neither documents nor payment rows.

Rollback is code-only: reverting the importer changes restores the previous strict pre-adjustment behavior. No schema migration or data backfill is planned.

## Open Questions

- None. The import behavior is defined as fixed amount `Diskon`, repeated once per invoice and owner group, with conflicting repeated values invalidating the group.
