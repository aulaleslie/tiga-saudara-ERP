## Context

Sales retain their immutable `date` and may have an active `reporting_date`, with every override retained in `reporting_date_audits`. Sale operational lists and detail pages already present `reporting_date ?? date`, but sales reports still query the original date. This causes reporting-period membership, displayed values, sort/group order, and exports to disagree with a sale's assigned marketing date.

The closest precedent is the completed purchase reporting-date change. Its report-layer helper defines an SQL-safe date-only `COALESCE` expression and purchase reports use that expression end-to-end. This change applies the corresponding policy to the sales list (local and global), sales by customer, the sold side of sales by product, sales tax, and sales-order completion.

## Goals / Non-Goals

**Goals:**

- Give every included sales reporting surface one effective-date definition: `DATE(COALESCE(sales.reporting_date, sales.date))`.
- Make a report's period filtering, date sorting/grouping, visible transaction date, and export date agree.
- Use the active `sales.reporting_date` value rather than audit history, so replacement takes effect immediately and clearing restores original-date reporting.
- Preserve MySQL/MariaDB and SQLite compatibility and avoid new schema or data migration work.

**Non-Goals:**

- Changing the original sale date, due date, references, payments, lifecycle, dispatch facts, stock movement, inventory valuation, or general-ledger chronology.
- Changing customer receivables or aged-receivables as-of, maturity, balance, or ageing semantics.
- Changing sales-delivery report selection or ordering, which is driven by approved dispatch/delivery events.
- Reclassifying sale returns by the original sale's reporting date; return aggregates retain their own completed-return date.
- Adding permissions, UI controls, or database columns.

## Decisions

### Centralize an effective sale reporting-date SQL expression

Add a report-layer helper equivalent to the purchase helper that returns a fully qualified, database-compatible `DATE(COALESCE(<alias>.reporting_date, <alias>.date))` expression. Every included query uses this for raw date comparisons, select aliases, and date order/group aggregates; model-backed display mapping uses `Sale::effective_date`.

Alternative considered: duplicate `COALESCE` calls in each service. Rejected because equivalent report behavior would drift over time and alias qualification differs across joins and subqueries.

### Apply the effective date only to analytical sales facts

The primary sale report (including global mode), sales by customer, sold aggregate in sales by product, sales-tax sale rows, and sales-order completion are reporting-period views of the sale fact. Their date filters, sorting/grouping, presentation, and exports therefore use the effective date. In the product report, the return aggregate continues to filter `sale_returns.date`, because it represents the completed return event rather than the original sale.

Blade report views that render the date column inline (sales-report, sale-by-customer-report) also use the model's `effective_date` accessor for display consistency with exports and query-service mappings.

Alternative considered: replace every `sales.date` use. Rejected because receivables and delivery reports have separate business clocks and must not silently change their balances, ageing, or delivery-event meaning.

### Preserve independent operational and accounting clocks

Customer receivables and aged receivables keep original sale date, due-date, payment, and as-of semantics. Sales delivery keeps approved dispatch/delivery dates. Stock, inventory, movement, and general-ledger reporting retain existing chronology. These exclusions match the original reporting-date override boundary and the established purchase-reporting precedent.

Alternative considered: treat reporting date as an accounting reposting date. Rejected because the override is a reporting-period classification only and must not rewrite operational or accounting facts.

## Risks / Trade-offs

- [A sale moves between report periods when an override is set] → This is the intended outcome; cover assigned, replaced, cleared, and absent override cases.
- [One report path keeps a direct `sales.date` dependency] → Add service-level and Livewire/export tests for every included surface, covering filters, sort/group order, rendered date, and exported date where applicable.
- [Date-time overrides create end-of-day boundary bugs] → Compare normalized date expressions to the user-selected date range, as the purchase helper does.
- [Users expect reporting dates to change receivables or stock] → Retain explicit exclusions and preserve original operational dates in sale history.

## Migration Plan

1. Deploy the report-service and presentation changes with focused tests; no migration or backfill is required because `sales.reporting_date` is already indexed.
2. Sales without an active override continue to report by their original `sales.date`.
3. If rollback is needed, revert only the report-query and presentation changes; original sale data and audit history remain intact.

## Open Questions

None. The agreed scope is the defined analytical sales reports, with receivable, delivery, return, inventory, and ledger clocks explicitly excluded.
