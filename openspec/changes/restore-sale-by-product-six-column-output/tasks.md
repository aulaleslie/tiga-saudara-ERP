# Tasks

## 1. Sales-only product aggregation

- [x] 1.1 Change the normalized dataset to group direct and bundle-item occurrences by product/compatible unit without sale-role separation; verify a fixture with direct 2 units/60000 plus component 1 unit/25000 produces one row with quantity 3, sales 85000, and weighted average 85000/3, including selected multi-business aggregation and distinct incompatible units.
- [x] 1.2 Preserve POS residual parent revenue and component quantity/value attribution before catalog filters; verify exact revenue conservation, ATK component under a LAPTOP parent, overlapping category descendants, parent-only/component-only product selection, and split-owner zero-quantity placeholder handling without phantom rows or double-counting.
- [x] 1.3 Remove report-only HPP evaluation, role/completeness dataset fields, and unused helpers after reference checks; verify missing/zero/corrupted cost snapshots cannot affect sales results, stored transactions/snapshots remain unchanged, and other report consumers are untouched.
- [x] 1.4 Align actual query-service callers and preserve effective date, fulfilled eligibility, current settlement-modified values, tax inclusion/exclusion, and document-discount rules; verify the existing focused date/eligibility tests and new taxed bundle fixture without additional return subtraction or informational-price allocation.
- [x] 1.5 Update the calculation section in docs/reports/sale-by-product-report-guide.md; verify its mixed direct/component example, ATK filter interpretation, residual-parent example, and documented snapshot coverage agree with passing fixtures.

## 2. Exact six-column screen and exports

- [x] 2.1 Restore exactly Kode Produk, Nama Produk, Kuantitas Terjual, Satuan, Total Nilai terjual, Harga Penjualan Rata-rata in the Blade table and sales-only footer; verify rendered heading order, six-cell data/footer rows, empty-state colspan, pagination totals, and absence of role/modal/status/Included in bundle/partial/unavailable result labels.
- [x] 2.2 Restore Excel/CSV headings, every data/footer row, metadata merge ranges (A:F), number formats, and any field mappings to six columns using the same grouped dataset; verify actual serialized XLSX/CSV headings, worksheet dimensions, numeric quantities/zero sales/averages, no hidden status columns or additional status sheet, and all-page row/total parity with preview.
- [x] 2.3 Update report description and export documentation to the six-column sales-only contract; verify documented field order and footer placement match the rendered and serialized fixtures while standard report metadata remains intact.

## 3. Preserve filters and verify integration

- [x] 3.1 Keep existing customer/category/brand/product and authorized business filters, descendant matching, OR/AND logic, chips above inputs, applied summaries, clear/remove, cancel/reset, pending-change/export guards, and explicit bulk-selection limits; verify existing filter refinement and scope tests plus ATK/brand/product combined direct/component fixtures.
- [x] 3.2 Preserve Asia/Makassar calendar presets, exact-date auto-matching, custom no-op, compatible Livewire lazy bindings, and the single Livewire Alpine runtime; verify the preset/manual-date tests and record a browser smoke check showing preset-to-date and date-to-preset synchronization without auto-Apply.
- [x] 3.3 Add a >500-source-row fixture crossing both detail and component chunk boundaries with repeated product identities; verify accurate one-row grouping and totals, sibling lookups bounded to current chunks, and no full-period sibling map or unbounded historical ID list.
- [x] 3.4 Run affected SaleByProductReport, SaleByProductSnapshotAndBundle, SaleByProductFilterRefinement, effective-date, and eligibility tests with explicit isolated-test paths/filters, and strict OpenSpec validation; record exact commands/results, broadening only for failures or identified regressions.
- [x] 3.5 Complete one focused browser/download smoke check for ATK-only, combined direct/bundle product, and multi-business results; record six visible columns, matching XLSX/CSV, selected-filter stability, exact bundle totals, and the unchanged transaction/schema state. Mark any unavailable browser or isolated database verification pending rather than complete.

## Workflow follow-up

- Review and archive after implementation and focused verification. When syncing/archiving, align the capability Purpose with sales-only product/unit reporting; keep historical HPP change artifacts intact.
- A future request for unassigned legacy revenue allocation or modal reporting is separate scope.
