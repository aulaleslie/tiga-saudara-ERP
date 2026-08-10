## Context

Purchases preserve their original `date` and hold an optional active `reporting_date`; each change is separately retained in `reporting_date_audits`. The Purchase model already exposes this as `effective_date`. Purchase operational views use it, but purchase report query services still use `purchases.date` for period filtering, date ordering, grouping, screen mapping, and exports.

The affected analytical/period report paths are the primary purchase report, purchase-by-supplier, purchase-by-product, purchase-order-completion, and purchase-side rows in the sales-tax report. Aged payables, supplier payables, and purchase delivery have different date semantics: as-of liability age, document/due-date maturity, and receipt completion respectively.

## Goals / Non-Goals

**Goals:**

- Give every in-scope purchase report one consistent effective reporting-date definition: `COALESCE(purchases.reporting_date, purchases.date)`.
- Make filters, sort/group calculations, displayed dates, and exports agree for a given report.
- Use the active column on the purchase, so a replacement is immediately reflected and a cleared override falls back to the original date.
- Preserve cross-database compatibility with the existing MySQL/MariaDB and SQLite test environments.

**Non-Goals:**

- Changing the purchase's original date, due date, reference generation, operational workflow, payments, stock, or audit history.
- Reading reporting-date audit history to calculate a report date.
- Changing purchase-delivery date selection, aged-payables ageing/as-of rules, supplier-payables maturity/as-of rules, or any sale reporting behavior.
- Adding schema, permissions, or user controls.

## Decisions

### Resolve from the active purchase value, not audit history

All in-scope queries will use `COALESCE(purchases.reporting_date, purchases.date)` as the effective reporting-date expression. The `reporting_date` column is the authoritative active override, while the audit table is immutable history. This ensures the latest replacement is used and a latest clear action correctly restores the original date.

Alternative considered: query the newest reporting-date audit for each purchase. Rejected because it duplicates the state already materialized on the purchase, adds joins/subqueries and ordering complexity, and can incorrectly treat a cleared override as active unless special-cased.

### Apply effective date end-to-end within each in-scope report

For the primary purchase report, the transaction-date basis will refer to effective reporting date, while the due-date basis remains `purchases.due_date`. For the other in-scope reports, period constraints, date sort/group expressions, selected date aliases, row mappers, and exports will use the same effective expression/value. The UI wording will identify the transaction-date basis as reporting date to avoid implying the immutable source date is used.

Alternative considered: change only table/export display. Rejected because a document could display within one period while its filter and total belong to another.

### Keep operational and ageing report clocks independent

Purchase delivery remains driven by approved receiving-note dates. Aged Payables and Supplier Payables retain original purchase and due-date/as-of logic because their age and outstanding balance are operational financial facts, not a reporting-period classification. This preserves the original reporting-date constraint for those specialised calculations.

Alternative considered: make every `purchases.date` reference effective. Rejected because it would silently change ageing, maturity, and delivery meanings beyond the requested reporting-period behavior.

### Centralize the SQL expression for query consistency

Implementation will introduce a small report-layer helper (or equivalent shared query-expression method) that returns the fully-qualified effective purchase-date SQL expression. Query services will use it for raw filters, select aliases, order-by, and aggregate grouping, while Eloquent-backed row mappers use the existing `effective_date` accessor. This avoids divergent copies of the fallback rule.

Alternative considered: only use the accessor. Rejected because joined/aggregate SQL filters and ordering cannot rely on an Eloquent accessor.

## Risks / Trade-offs

- [Date filtering changes period membership after an override] → This is the intended business result; cover override, replacement, clear, and no-override cases in focused tests.
- [Raw SQL expression varies by database] → Restrict it to standard `COALESCE`, retain existing date-only values, and run focused tests on SQLite plus the project’s broader fresh-SQLite suite when practical.
- [A report retains an unnoticed direct `purchases.date` usage] → Test query filtering, sort/group order, mapped screen value, and export value for every in-scope service.
- [Users confuse reporting date with original purchase date] → Rename the primary report date-basis label and keep original dates available in purchase audit/history views.

## Migration Plan

1. Deploy the code and focused tests; no migration or backfill is required because `reporting_date` already exists and is indexed.
2. Existing reports immediately fall back to `purchases.date` for every purchase without an active override.
3. If rollback is needed, revert the report-query and presentation changes; original document and audit data remain untouched.

## Open Questions

None. The scope intentionally includes the purchase-side sales-tax period query and intentionally excludes payables ageing/maturity and receiving-date reports.
