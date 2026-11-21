# Design

## Context

See proposal.md for motivation and verified data coverage. The current report has a bounded read-side pipeline for sale_details and sale_bundle_items, projects own HPP and assigned commercial values, and groups by product/unit/role. Its screen shows role and modal columns; XLSX/CSV additionally contain status columns. The report's durable specification now requires those additions, so hiding columns alone would leave grouping and contract behavior wrong.

The original exporter before commit f3346b06 had exactly six fields and the original query read only sale_details. The user explicitly clarified that ATK selects the product being sold, including components, and approved merging direct/component sales. Consequently this is a six-column presentation restoration with product-level commercial aggregation, not a return to the original parent-only query.

Current filters include descendants, brands, global customer/product lookup, authorized business selection, tokenized checkbox search, chips above inputs, applied summaries, previous/current calendar presets, and exact date-range/preset matching. Livewire 3.0.5 requires supported lazy bindings; the prior duplicate Alpine bootstrap fix is also required for visible date reactivity. These working changes stay in place.

Local read-only discovery found 1,732 attached POS-linked bundle items across 549 sales dated August 22 through November 14, 2026. All 1,714 positive-quantity rows have positive assigned subtotals. No non-POS or standalone bundle items were present. The user identified this database as a production snapshot from last week; this is evidence of scope, not proof of current production contents.

## Goals / Non-Goals

**Goals:** Exactly six result/export columns, merged product/unit rows, numeric selling amounts, unchanged filter UX and authorization, and conserved POS commercial revenue through bundle decomposition.

**Non-Goals:** HPP, role/status output, Included in bundle or partial/unavailable cell annotations, profit, new revenue allocation, historical data cleanup/backfill, transaction or snapshot policy changes, filter redesign, or unrelated report/permission changes.

## Decisions

### Preserve commercial projection and aggregate by product/unit

Keep the existing detail/component projection and POS parent residual calculation. A reported product is the sale detail's product or the bundle item's product respectively. Resolve category/brand/product filters against that product; do not require a component's parent to match the same filters. Apply transaction business/date/customer/tag eligibility equally to both sources.

Remove sale_role from aggregate identity, row sorting ties, DTO output, and display. Use persisted product identity and compatible unit as the group key, not historical display spelling or the source table. Preserve current catalog name/code precedence with snapshot fallback for missing catalog records. Do not merge distinct missing product identities merely because names coincide. No new unit conversion is introduced.

For each group:

- quantity = sum of included direct and bundle-item persisted quantities;
- sales value = sum of included tax-exclusive direct residual and component commercial values;
- average selling price = sales value / quantity when quantity > 0, otherwise the existing zero-safe result.

Calculate averages after combining rows and selected businesses. Never average line averages or retain duplicate direct/component rows while hiding their role. Missing HPP does not affect any sales number or inclusion.

### Retain exact bundle revenue without duplication

For established POS parent/child allocations, parent report revenue is the stored parent commercial subtotal minus all assigned sibling component subtotals, using the existing corresponding tax exclusion. Use stored subtotals, not rounded price multiplied by quantity. Fetch all relevant siblings before catalog filtering even when only an ATK component is visible. Keep 500-row streaming and sibling lookups limited to current-batch parent IDs; do not reintroduce a full-period map or unlimited whereIn bindings.

A component-only owner group can have a zero-quantity placeholder parent whose subtotal represents component revenue. Its residual is zero; the real component quantity/value must remain in the selected business's results. Zero-quantity/zero-value placeholder projections must not create a separate empty product row. Components' stored quantities are already extended; do not multiply them again by parent quantity.

Preserve current effective sale reporting date, fulfilled eligibility, persisted settlement-modified quantities/values, tax-included/excluded treatment, and document-discount convention. No independent return subtraction or replacement-cost adjustment is introduced.

Existing persisted numeric commercial zero remains zero. Informational prices never become revenue. No absent non-POS historical allocation is invented for this change. Existing known standalone commercial behavior can remain as a numeric source; do not remove supported source linkage merely because the snapshot has no such rows. Existing unproven historical allocations are not repaired or reinterpreted by this work, and no new table/export status vocabulary is introduced for them. The acceptance dataset is verified ordinary sales and assigned POS components; a future request to allocate unassigned non-POS revenue is separate work.

### Remove report-only HPP and status processing

Stop reading/evaluating cost snapshots for this report and remove modal totals/completeness flags and role/status view/export mapping. Remove SaleByProductCostSnapshotEvaluator only if reference search confirms it is exclusive to this report; otherwise leave unrelated consumers untouched. Simplify report rows to product_id, code/name/unit, sold_quantity, sold_value, average_sales_value, with a sales-only total.

Do not drop stored snapshots, modify SalesCostSnapshotService or AverageCostResolver, or change other financial/stock reports. The old source-provenance distinctions are no longer a reason to split rows or emit labels in this report. Avoid retaining dead cost calculations simply because their columns are hidden.

### Restore table/export geometry together

The strict order for preview and XLSX/CSV is:

| Position | Field |
|---|---|
| 1 | Kode Produk |
| 2 | Nama Produk |
| 3 | Kuantitas Terjual |
| 4 | Satuan |
| 5 | Total Nilai terjual |
| 6 | Harga Penjualan Rata-rata |

Update headings, every data/footer row, empty state colspan, table width, number formats, and spreadsheet metadata merge ranges to six columns (A:F). The total footer sums sales value in E; it does not sum averages or quantities of unlike units. Preserve existing metadata rows outside the result table and filter summaries outside the six-column preview. Retain WithStrictNullComparison so actual CSV/XLSX files preserve numeric zero. Do not hide forbidden fields in extra sheets, hidden columns, cell annotations, or supplementary result status output.

Preview, full-result totals, and both downloads use the same grouped dataset and deterministic sorting. Preserve current applied-filter snapshot/hash guards, pagination, authorization sanitization, and request-scoped dataset reuse. The legacy build() caller path must be aligned or retired only after checking real callers; do not leave two contradictory query contracts active.

### Preserve filter and calendar behavior

This revision does not redesign the controls. Keep authorized multi-business scope, global customer/category/brand/product search, OR within each catalog filter and AND between filters, descendants without duplicate matches, customer selection persistence, all selected chips above inputs, clear/remove and explicit product bulk selection limits. Keep pending changes, cancel/reset, and applied-only summaries.

Retain Hari Ini, Minggu Ini/Lalu, Bulan Ini/Lalu, Tahun Ini/Lalu and Rentang khusus in Asia/Makassar with Monday–Sunday weeks. Presets fill dates, custom selection preserves them, manually entered exact preset boundaries select that preset, and other ranges select custom. Updating controls still requires Apply before report/export changes. Do not revert the single Livewire-owned Alpine runtime or compatible lazy bindings.

## Risks / Trade-offs

- A historical-code checkout would also revert useful filters → revise query/output deliberately instead of reverting the entire old commit.
- Combining source roles can duplicate revenue → conservation fixtures, source identity accounting, and parent residual calculation before catalog filters.
- Removing HPP accidentally touches shared accounting behavior → reference search and report-scoped cleanup only; assert transaction/snapshot rows unchanged.
- Six-column preview with eleven-column downloads → inspect actual serialized CSV/XLSX, metadata ranges, numeric cells, and footer width.
- Last-week snapshot is not today's production → record this coverage limit; do not silently introduce a new legacy allocation policy.
- Performance fixes regress during simplification → >500-row fixture crossing both streams and bounded sibling-ID assertions.

## Migration Plan

No schema/data migration is required. Deploy query, preview, export, and documentation together, then perform the focused product/category/bundle smoke check. Rollback restores the prior report code, without touching sales or snapshots. The existing archived HPP change remains historical context; this delta supersedes only this report's output and grouping requirements.
