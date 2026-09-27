## Context

Purchase and sales imports are line-oriented: one source invoice can span multiple CSV rows, while document-level fields such as `Total`, `Pembayaran`, `Sisa Tagihan`, and `Sisa Tagihan Hari Ini` are repeated on each row. The current importers group rows into one purchase or sale document, calculate `paid_amount` as `totalWithTax - sisa_tagihan`, and set payment status on the document header, but they do not create matching `purchase_payments` or `sale_payments` rows.

Manual payment entry already treats payment rows as the operational ledger and recalculates header paid/due/status values from those rows. Recent purchase reporting work also expects active purchase payments to be the source of truth for payment totals. Future imports should therefore produce the same ledger shape as manual entry whenever an imported document is paid or partially paid.

## Goals / Non-Goals

**Goals:**

- Create one active payment row per future imported purchase or sale document when imported paid amount is greater than zero.
- Keep imported document header balances and active payment rows reconciled.
- Validate repeated invoice-level payment fields across each invoice and owner group before creating documents.
- Keep purchase and sales import behavior aligned while respecting their existing model and status casing conventions.
- Fail an invoice group clearly when payment data is inconsistent or the cash payment method cannot be resolved.

**Non-Goals:**

- Backfilling historical imported purchases or sales.
- Supporting multiple payment methods from CSV imports.
- Inferring non-cash payment methods from tags, notes, bank fields, or customer/supplier names.
- Changing existing import ownership, stock, dispatch, tax, or product-price synchronization behavior.
- Changing manual payment create/edit/invalidation flows.

## Decisions

### Decision 1: Use `Pembayaran` as preferred paid amount

The importer will parse the invoice group's `Pembayaran` value and use it as the paid amount when present and non-blank. If `Pembayaran` is blank or missing, it will fall back to `calculated document total - outstanding balance`.

Rationale: the source export already carries explicit payment amount and outstanding balance. Prefer the explicit paid amount so imported payment rows mirror source data, while retaining compatibility with older templates that do not include `Pembayaran`.

Alternative considered: always derive paid amount from total minus outstanding balance. This keeps current behavior but ignores a source field that is better suited for payment ledger creation.

### Decision 2: Prefer `Sisa Tagihan Hari Ini` over `Sisa Tagihan`

Outstanding balance resolution will prefer `Sisa Tagihan Hari Ini` when present, then fall back to `Sisa Tagihan`.

Rationale: the active source CSV includes both columns, and the "today" field reflects the current outstanding balance intended for import. Keeping `Sisa Tagihan` as fallback preserves compatibility with older exports.

Alternative considered: always use `Sisa Tagihan`. This would conflict with the selected source semantics and could import stale balances when both columns exist.

### Decision 3: Validate payment fields at invoice-group scope

Before creating an imported document, the importer will verify that every row in the invoice and owner group has consistent document-level values for payment validation: `Total` when mapped/available, `Pembayaran` when mapped/available, preferred outstanding balance, and the calculated line total. If the paid amount, outstanding balance, and calculated total do not reconcile within the existing monetary tolerance, the whole group will be marked invalid.

Rationale: imports are line-oriented, but payment rows are document-level. Creating per-line payments would duplicate payments, while silently choosing the first conflicting value would hide bad source data.

Alternative considered: choose the first row's payment fields and ignore later row differences. This is simpler but unsafe for accounting imports.

### Decision 4: Create one cash payment row per paid imported document

When the resolved paid amount is greater than zero, the importer will create exactly one active `PurchasePayment` or `SalePayment` row in the same database transaction as the imported document. The payment date will be the document date, the payment reference will be the generated ERP document reference, and `payment_method_id` will point to the existing cash payment method. The payment's text `payment_method` should remain consistent with existing module conventions.

Rationale: this matches manual payment ledger shape while keeping CSV imports simple. Using the generated ERP reference makes the payment traceable to the local document even when the source invoice number is duplicated across settings or owners.

Alternative considered: use the imported invoice number as payment reference. This is source-friendly but less consistent with manual ERP payment references and can be ambiguous across owner-split imports.

### Decision 5: Fail if cash payment method is unavailable

The importer will resolve a cash method from `payment_methods` using `is_cash = true`, falling back to a case-insensitive `CASH` name only if needed. If no cash payment method exists and a positive imported paid amount requires payment creation, the invoice group will fail.

Rationale: a positive paid amount without a payment method would recreate the same audit gap in a different form. Failing the group gives operators a clear setup issue to fix.

Alternative considered: create payment rows with a null `payment_method_id`. This is looser, but manual payment validation requires a method and reports increasingly rely on method metadata.

### Decision 6: Future imports only

This change will not create a migration or command to backfill historical imports. Existing documents remain unchanged.

Rationale: historical rows may include old header semantics, missing payment methods, or corrections that need separate operational review. The goal here is to prevent new inconsistencies.

Alternative considered: automatic backfill during deployment. Rejected because it would mutate financial history without a user-reviewed reconciliation process.

## Risks / Trade-offs

- Payment totals may differ by rounding between CSV totals and importer-calculated totals. → Use a small monetary tolerance and cover boundary cases in tests.
- Cash payment method setup may be missing in some environments. → Fail only affected paid invoice groups with a clear import error; unpaid groups can still import without payment rows.
- Purchase and sales modules use different payment status casing (`PAID`/`UNPAID` versus `Paid`/`Unpaid`). → Preserve existing module conventions and assert them separately.
- Reusing ERP document reference for payment reference can produce a payment reference that differs from the source invoice. → Preserve source invoice on the purchase/sale document and use the payment relation for local audit.
- Line-oriented validation may reject invoices that were previously accepted despite inconsistent repeated fields. → This is intentional for paid imports because payment ledger creation requires one authoritative document-level amount.

## Migration Plan

1. Add focused failing tests for purchase import payment creation, sales import payment creation, partial payments, unpaid imports, mismatch failures, and missing cash method failures.
2. Add or adjust CSV mapping so purchase and sales import rows can distinguish `Sisa Tagihan Hari Ini`, `Sisa Tagihan`, and `Pembayaran`.
3. Implement shared or parallel helper logic for resolving payment summary data from an invoice group.
4. Create `PurchasePayment` and `SalePayment` records within the existing invoice-group transactions after the purchase/sale has an ERP reference and before rows are marked processed.
5. Run focused import tests, then a broader `php artisan test` or `composer test:fresh-sqlite` pass when practical.

Rollback is code-only for future imports. Since no schema changes or historical backfill are planned, reverting the code stops new payment-row creation without requiring data migration.

## Open Questions

None. The payment amount source, outstanding balance precedence, payment method, date, reference, partial/unpaid behavior, mismatch behavior, and backfill scope have been decided.
