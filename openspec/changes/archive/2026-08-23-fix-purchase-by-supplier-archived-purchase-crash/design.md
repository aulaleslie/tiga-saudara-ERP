## Context

The report starts from `PurchaseDetail`, manually joins `purchases`, and eager-loads `purchase.supplier`. A manual join is not governed by the `Purchase` model's `ArchivingScope`, so rows belonging to archived purchases remain in the result. The eager-loaded `purchase` relationship does apply that scope and therefore resolves to `null`; the Livewire running-total transformation then dereferences the relationship and crashes.

The same query service feeds filter snapshot counts, paginated screen results, running and grand totals, and Excel/CSV exports. The fix must preserve that shared dataset boundary and existing business/date/supplier/tag/category behavior.

## Goals / Non-Goals

**Goals:**

- Exclude archived purchase headers before their details can enter any Purchase by Supplier report calculation or output.
- Preserve parity across screen rows, counts, pagination, totals, sorting, and exports.
- Cover the reported failure with a focused regression test that includes both active and archived purchases in the same selected period and business.

**Non-Goals:**

- Restoring or modifying archived purchase data.
- Changing purchase archival semantics or the global archival scope.
- Broadly refactoring report query construction or auditing every other report in this change.
- Running or planning the full test suite.

## Decisions

### Filter archived headers in the shared query service

Add an explicit `purchases.archived_at IS NULL` constraint to `PurchaseBySupplierReportQueryService::build()`. This makes archival eligibility part of the SQL dataset before pagination and aggregation, and every current consumer receives the same result automatically.

Using a null-safe dereference only in the Livewire component was rejected because it would hide the crash while archived rows could still affect counts, page boundaries, supplier totals, grand totals, and exports. Changing `PurchaseDetail::purchase()` to include archived parents was also rejected because it would broaden relationship behavior throughout the ERP and would expose archived documents in a normal report.

### Verify behavior at the existing report feature boundary

Add focused coverage to the existing Purchase by Supplier report tests. The regression setup will create an active purchase and an archived purchase that both match the selected setting and date range, then assert that filtering succeeds and only the active purchase contributes rows and totals. Where existing export tests expose the shared query cleanly, add or extend a focused assertion that the archived row is absent from export data; avoid duplicate tests of spreadsheet formatting.

This boundary is preferred over a full-suite run because the code change is localized and the meaningful regression surface is the report query and its direct consumers.

## Risks / Trade-offs

- **[Risk] A query path could bypass the shared builder** → Confirm the Livewire screen, snapshot count, and Excel/CSV actions all obtain their builder from `PurchaseBySupplierReportQueryService::build()`.
- **[Risk] Archived rows previously affected totals or pagination without crashing** → Assert the filtered count and monetary contribution using mixed active/archived fixtures.
- **[Trade-off] Sibling purchase reports may contain the same manual-join pattern** → Keep implementation scoped to the reported capability; record a separate follow-up if focused inspection reveals another confirmed defect.

## Migration Plan

No data migration is required. Deploy the query constraint and focused regression test together. Rollback consists of reverting the application change; no persisted data is altered.

## Open Questions

None.
