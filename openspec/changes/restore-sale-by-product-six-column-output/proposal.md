# Proposal

## Why

Penjualan per produk needs its original six-column sales output, rather than HPP, role, or completeness reporting. Users still need all current filter improvements and product-based results that include products sold as bundle components.

## What Changes

- **BREAKING**: Restore exactly Kode Produk, Nama Produk, Kuantitas Terjual, Satuan, Total Nilai terjual, and Harga Penjualan Rata-rata on screen, XLSX, and CSV; remove HPP/modal, role, status, partial/unavailable, and Included in bundle output.
- Merge direct sale and bundle-component occurrences into one product/unit row across selected authorized businesses. Compute average selling price from combined tax-exclusive sales value divided by combined sold quantity.
- Keep component-aware numeric commercial calculations: include persisted component selling values and residual parent revenue once. Apply category/brand/product filters to the product being reported, including component products, rather than to the enclosing parent.
- Preserve current filters, descendant categories, checkbox selection, selected chips above inputs, applied summaries, period presets/custom-date matching, cancel/reset, sorting, permissions, and applied-filter export guards.
- Remove report-only HPP evaluation and role grouping where no longer needed; preserve stored snapshots and every other report/transaction workflow.
- Use focused regression and serialized export verification, including a chunk-boundary fixture and a targeted browser smoke check.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sale-by-product-report`: Six-column output, combined product/unit aggregates, numeric bundle commercial values, and matching exports while preserving filter behavior.

## Impact

Affects SaleByProductReport query/projection, Livewire result/totals integration, Blade table, Excel/CSV export layout, report description/documentation, and focused tests. It supersedes this report's role/HPP presentation introduced by add-sale-by-product-snapshot-costs; it is not a full Git revert to the old parent-only query.

No database migration, backfill, snapshot writer change, or transaction mutation is required. The local database, identified by the user as last week's production snapshot, contains 1,732 POS-linked attached components across 549 sales and no non-POS or standalone component rows; production changes after that snapshot have not been audited. This change does not introduce a historical allocation or repair workflow for absent legacy cases.
