# Design

## Context

See proposal.md for motivation. `SaleByProductReportQueryService` currently reads only sale_details, applies effective reporting dates and fulfilled-sale eligibility, and aggregates current persisted quantities/values. The report has category/product arrays, a 500-product explicit bulk-selection action, and no brand filter. Its export currently has six columns and uses applied-filter validation.

`SoldSerialNumberReport` provides reusable business checkbox selection, product checkboxes, a 25-result search cap, collapsed/expanded selected chips, and pending-filter feedback. Its required-product validation, original-date rule, and physical-return logic are not appropriate for this report.

Source discovery distinguishes: POS split-planned assigned component revenue; standard Sales non-billable attached components (`SaleNormalizer::normalizeBundleItems`); standalone commercial bundle rows; and ambiguous historical attribution. POS parent sub_total can contain child revenue while its unit_price represents the residual. Multiplying rounded parent unit_price by quantity is therefore not the authority for exact residual revenue. Component tax accessors used for standard Sales intentionally ignore attached component tax, whereas POS stores assigned component tax.

Read-only local observations: 1,732 bundle-item rows, all POS attached, all with cost snapshots; 1,714 positive subtotals and 18 zero-quantity/zero-subtotal rows. No non-POS/standalone samples and no taxed bundle samples were available. Those cases require explicit fixtures, not assumptions from this sample. Snapshot columns and informational prices were introduced by nullable additive migrations; old child price presence alone does not establish commercial attribution.

The durable report spec contains stale return-column, return-sort, and category-all-match descriptions absent from current query/UI/export. This delta deliberately updates those sections for the agreed report, preserving no-independent-return-subtraction behavior. Historical cost writer behavior remains unchanged: AverageCostResolver rejects non-positive acquisition averages, so MISSING_AVERAGE_PRICE cannot be reclassified by this report as valid zero.

## Goals / Non-Goals

**Goals:** Build one consistent read-side dataset for preview, totals, sorting, and exports; preserve commercial amounts through bundle decomposition; distinguish valid zero from unknown; support the agreed selectors without widening business access.

**Non-Goals:** Snapshot backfill or writer changes, new schema, profit/margin columns, new revenue weighting, return/replacement financial reconciliation, serial-report changes, document-discount redistribution, current catalog price substitution, or full-suite verification as a default gate.

## Decisions

### Normalize persisted sale rows before filtering and aggregation

Extend the report query layer with focused bundle projection/classification support rather than embedding accounting branches in Livewire or the export. Produce a normalized read-only dataset with source row identity, sale/business/effective date, product and compatible unit, sale role, quantity, commercial value, own HPP, and completeness/status fields. Use sale detail and bundle item source keys to prevent overlap. Aggregate into product/unit/role rows; role distinguishes direct/parent from bundle component. Standalone commercial bundle items use the component role. Current catalog names/codes and taxonomy remain the lookup authority, with historical name/code fallback for missing products.

Parent commercial projection must compute all sibling assignments before category/brand/product filtering. Otherwise filtering to a component or excluding a sibling changes the residual. Pre-aggregate child monetary values by parent before joining to avoid multiplying parent quantity/cost by child count. Include component-only owner groups even when their placeholder parent quantity is zero. Keep each component's persisted quantity (already extended); do not multiply by parent quantity again.

Retain the report's existing unit conventions for detail rows, resolve component display units compatibly, and do not merge incompatible unit rows. No new UOM conversion or historical snapshot reconstruction is introduced. Preserve existing effective reporting date, selected sales business, fulfilled eligibility, current persisted settlement-modified quantities, and customer/tag filters for both projection branches. Do not import serial-report status/date logic or independently subtract return/replacement aggregates.

### Attribute revenue using verified commercial semantics

| Projection | Revenue rule | Parent treatment |
|---|---|---|
| Ordinary detail | Existing persisted line commercial value | No child deduction without assigned revenue |
| Known POS allocated component | Persisted component subtotal and assigned tax | Subtract assigned component shares from containing parent |
| Standard Sales included component | Included in bundle; no independently assigned revenue | Preserve parent commercial value |
| Known standalone commercial item | Own persisted subtotal and tax | No parent to deduct |
| Ambiguous old component | Unavailable; never promote informational price | Do not deduct speculative child amounts |

Use durable POS sale linkage (`pos_checkout_sales`) plus known posting lineage and structural monetary consistency; `line_group_key` can corroborate but its absence or an old nonzero price is not sufficient evidence either way. Distinguish known standard-Sales composition semantics from unknown historical writers conservatively. A legacy row inconsistent with an established posting shape gets unavailable component revenue with explicit coverage status; do not silently clamp a negative residual to zero. Test branch classification with known writer fixtures and structural inconsistencies.

For established POS allocations use exact stored subtotals, not rounded price × quantity. Parent tax-exclusive residual is its current tax-exclusive commercial subtotal minus all established assigned children's tax-exclusive shares. Only subtract tax when included under the persisted sale convention. Use component tax_amount directly for POS; standard included-component tax belongs to parent, while standalone items have self tax context. A tax breakdown that cannot support reliable attribution is unavailable rather than reconstructed from today's tax rates. Preserve existing document-level discount treatment; no new allocation or repeated discount subtraction.

Known numeric zero allocated revenue remains numeric zero. Included and unknown revenue are distinct nonnumeric statuses. When a component aggregate mixes known and unassigned/unknown revenue, display the known sales subtotal as partial and suppress average selling price; all-included rows display Included in bundle. Grand sales totals include each established commercial value once and label coverage when some attribution is unknown. This is a conservative presentation default, not a profit calculation.

### Use snapshot HPP with explicit completeness

Calculate valid own cost from cost_unit_snapshot × included current quantity using decimal arithmetic and round the aggregate only at display/export boundaries. Use a total-only snapshot only when the stored evidence establishes that it corresponds to the included quantity; otherwise mark incomplete rather than distributing an unverifiable old total over a changed quantity. Never sum parent and component cost into both rows. A placeholder parent marked NOT_FULFILLED_BY_GROUP contributes no phantom stock quantity or cost.

Create an explicit source/provenance interpretation shared by query projection and export, drawing on existing SalesCostSnapshotService and historical import/backfill sources. A snapshot is not complete merely because its number is non-null or zero: check source, snapshot timestamp, finite/nonnegative value, and missing/warning provenance. Honor valid historical zero snapshots and explicitly legitimate non-stock zero sources. MISSING_AVERAGE_PRICE and missing/invalid snapshots are incomplete even at zero. Historical fallback sources keep their existing provenance and warning meaning; do not relabel them as missing solely because they were backfilled.

For complete positive-quantity rows: average HPP = total valid HPP / total quantity, and average selling price = complete commercial revenue / corresponding row quantity. Both are quantity-weighted across businesses, not averages of line averages. Cost-incomplete rows show known HPP as partial with no average HPP. Missing selling value does not imply missing HPP or vice versa. Complete zero-quantity rows remain zero-safe. Grand HPP sums known own costs over the whole filtered dataset with a partial marker if necessary; never add unlike-unit quantities or sum averages into a misleading grand average.

### Use serial-report selectors without adopting its business rules

Use existing `business-checkbox-selector` (active authorized business default) and reusable equivalent checkbox/dropdown markup for global categories, brands, and products. Preserve authorized scope sanitization on generation/export and existing empty-business fallback. Do not require product selection: ATK-only generation must work. Keep customer/tag controls and tag logic unchanged; category/brand/product filters are OR internally and AND together. Remove misleading category all-match choice. Resolve descendant category IDs with cycle-safe traversal and deduplication; display category parent paths.

Match the serial report's open-on-multiple-check behavior, tokenized product name/code search, 25-result cap with notice, three-chip collapse/expand, clear selection, and keyboard/outside-click behavior. Preserve the existing explicit select-all-matching-products function and its 500-item warning ceiling; no implicit select-all or hidden truncation. Add brand IDs and all relevant normalized selector state to filter DTO validation, serialization/hash, cancel/reset, and export guard. Pending changes leave previously applied results stable until Apply. No business-selector changes to unrelated reports.

### One dataset for preview and downloads

The query/projection result drives pagination, complete filtered grand totals, deterministic sorting, and XLSX/CSV. Add Dijual sebagai and the two modal columns, plus explicit status labels embedded in relevant displayed/exported values or companion completeness fields as necessary to preserve numeric cells. Complete numeric amounts remain numeric in spreadsheets, including zero; missing averages export blank with an explanatory status, never zero. Metadata and ranges/formatting must expand from the old six-column layout. Snapshot guards include brands, descendant category selection inputs, products, and business scope. The report remains a read-only recomputation with applied-filter validation, not immutable saved transaction data.

## Risks / Trade-offs

- Heterogeneous legacy revenue semantics → proven posting classifications, explicit unavailable labels, and fixtures for ambiguous records; no catalog-price inference.
- POS full parent subtotal plus child subtotal double-counts → sibling pre-aggregation and exact conservation assertions before filters, including split-owner component-only groups.
- Missing HPP appears as false zero or diluted average → provenance-based completeness and suppressed incomplete averages.
- Non-POS/taxed samples absent locally → targeted synthetic fixtures using documented writer behavior and existing tests.
- Existing return semantics differ from net accounting HPP → retain this report's current behavior and label values as modal rather than net profit.
- Aggregate expansion creates query amplification → bulk joins/aggregates, bounded category traversal, no per-result-row queries, and focused query-growth checks.

## Migration Plan

No database migration or cleanup is required. Deploy report code and updated exports together. Verify focused fixtures and an ATK/multiple-business UI/export smoke check. Rollback is the previous report code; no transaction data changes need reversing. Future zero-cost capture changes and historical revenue repair remain separate work.
