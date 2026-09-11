## Context

`SaleByCustomerReportQueryService` starts from `SaleDetails` and joins `sales` directly. Laravel does not apply the `Sale` model's `ArchivingScope` to that manual join, while the separately eager-loaded `sale` relationship does apply it. An archived sale can therefore contribute a detail row whose `$detail->sale` relation is `null`. The export mapper converts that missing relation to a `-` date placeholder, and `SaleByCustomerReportExport` unconditionally passes the placeholder to `Carbon::parse()`.

The query already selects the effective sale date as `sale_date` with the same `COALESCE(reporting_date, date)` expression used for filtering and sorting. The correction should preserve that established effective-date behavior and work for both XLSX and CSV exports.

## Goals / Non-Goals

**Goals:**

- Exclude archived sales from Penjualan Per Customer query, screen, totals, and export results.
- Use one consistent effective date for query inclusion, ordering, mapping, and export output.
- Prevent XLSX and CSV generation from throwing when a mapped date is absent or invalid.
- Cover the failure with focused automated verification and leave final browser download confirmation to a human.

**Non-Goals:**

- Changing reporting-date override semantics.
- Changing report columns, grouping, discounts, taxes, totals, permissions, or filter snapshots.
- Repairing historical sale records or changing the database schema.
- Broadly refactoring every report exporter with similar date formatting code.

## Decisions

1. **Apply archive filtering explicitly to the joined `sales` table.** Add a qualified `sales.archived_at IS NULL` constraint to the report query. This mirrors `Sale`'s global archive scope at the SQL boundary, prevents archived rows from affecting pagination and totals, and keeps the eager-loaded relationship available for active rows. Relying only on defensive export formatting was rejected because archived sales would still incorrectly appear in report results.

2. **Treat the selected `sale_date` alias as the export mapper's authoritative date.** The alias is derived by `EffectiveSaleReportingDate::sqlExpression()` and is already consistent with filtering and sorting. Falling back to the active sale relation may be retained only for mapper callers that supply a model outside this report query. Reading only `$sale->effective_date` was rejected because it recreates the relationship-scope mismatch that caused the defect.

3. **Format dates through a defensive export boundary.** The exporter will format a valid date value as `d/m/Y` and preserve a neutral placeholder for a missing or unparseable value instead of throwing. Query filtering should normally guarantee a valid date, but the export boundary must remain safe for malformed legacy data or directly constructed mapper input.

4. **Use focused regression coverage.** Verification will target active effective-date output, archived-sale exclusion, and safe handling of a missing date. A full report-suite run is outside this change; a human will confirm the XLSX/CSV browser download flow.

## Risks / Trade-offs

- **[Existing users may have seen archived sales in this report]** → Exclusion is intentional and aligns the manual join with the model's established archive scope.
- **[Database alias values may be strings while relationship dates are Carbon objects]** → Normalize both through the same export date formatter.
- **[Defensive fallback can conceal malformed legacy dates]** → Keep the fallback limited to export presentation; focused tests still assert valid active records use their effective date.
- **[A similar pattern exists in Purchase By Supplier]** → Record it as separate follow-up scope rather than expanding this production fix without evidence of the same reported failure.

## Migration Plan

No data migration is required. Deploy the query, mapper, exporter, and focused test changes together. Rollback consists of reverting those code changes; no persistent data is modified.

## Open Questions

None.
