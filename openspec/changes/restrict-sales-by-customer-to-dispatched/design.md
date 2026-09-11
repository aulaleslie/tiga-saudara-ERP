## Context

`SaleByCustomerReportQueryService::build()` currently scopes detail rows by setting, archive state, effective reporting date, and optional customer/tag/category filters, but does not constrain `sales.status`. Because screen rendering, pagination calculations, total-based sorting, and XLSX/CSV export all derive from this builder, lifecycle-ineligible transactions can appear and contribute monetary values throughout the report.

The Sale model already treats exact `DISPATCHED` as its completed state through `Sale::scopeCompleted()`. Dispatch approval is responsible for moving a Sale from `APPROVED` or `DISPATCHED PARTIALLY` to `DISPATCHED` after all fulfillment obligations are approved, including non-stock acknowledgements. The report can therefore rely on the persisted header status instead of independently reconstructing dispatch completeness.

## Goals / Non-Goals

**Goals:**

- Make exact `Sale::STATUS_DISPATCHED` a mandatory predicate of the shared Penjualan Per Customer dataset.
- Keep screen results and exports consistent without duplicating status logic.
- Prove the boundary with focused report feature tests covering fully dispatched and representative non-eligible statuses.

**Non-Goals:**

- Recalculate dispatch completeness from dispatch-detail quantities.
- Include or net returned and partially returned transactions.
- Change Sale or Dispatch lifecycle transitions.
- Add a user-selectable status filter, schema changes, browser automation, or full-suite verification requirements.

## Decisions

### Filter by exact persisted Sale status in the shared query

Add an exact `sales.status = Sale::STATUS_DISPATCHED` constraint to `SaleByCustomerReportQueryService::build()`. This is the narrowest authoritative rule and automatically applies to every consumer of the builder, including customer-total subqueries cloned from it.

Alternative considered: sum approved dispatch details and compare them with sale-detail demand inside the report. This duplicates fulfillment-domain logic, is more expensive, and risks diverging on bundles and non-stock acknowledgements.

### Exclude returned lifecycle states

`RETURNED` and `RETURNED PARTIALLY` are not exact `DISPATCHED` states and will be excluded. Although those transactions were dispatched historically, including them would require defining whether the report presents gross historical sales or net sales after returns. That accounting-policy expansion is outside this focused correction.

Alternative considered: treat all post-dispatch states as fully dispatched. This would preserve historical fulfillment visibility but could overstate customer sales because the report does not currently add compensating return rows.

### Verify at the shared-query/report boundary

Focused feature tests will create otherwise matching Sales with different lifecycle statuses and assert that only exact `DISPATCHED` detail rows appear. Existing snapshot/export paths use the same builder, so targeted export assertions should verify that the predicate is preserved without duplicating exhaustive format coverage. A human will perform browser checks for filter, table, totals, and export behavior.

Alternative considered: run the entire planning or project test suite and add browser automation. The change is confined to one query predicate, so focused Laravel tests plus manual browser verification are proportionate.

## Risks / Trade-offs

- [Previously visible approved or partially dispatched sales disappear] → This is the intended correction; focused tests document the exact lifecycle boundary.
- [Returned transactions no longer appear even though they were historically dispatched] → Keep the rule explicit and defer gross-versus-net return reporting to a separate change.
- [Existing fixtures rely on a default non-dispatched status] → Update only report-test fixtures that are intended to represent eligible completed sales; preserve explicit excluded-status cases.
- [UI and export behavior drift] → Apply the predicate once in the shared builder and verify both query results and a focused export path.

## Migration Plan

Deploy the query and test changes without a database migration or data rewrite. Rollback consists of reverting the shared-query predicate; no persisted state needs restoration.

## Open Questions

None. Returned-state accounting and any future selectable status filter remain separate product decisions.
