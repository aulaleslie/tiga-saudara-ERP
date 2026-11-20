# Tasks

## 1. Bundle-aware report dataset and revenue

- [x] 1.1 Extend the report query layer with normalized detail/component/standalone projections and deterministic product/unit/role grouping; verify focused SaleByProductReport tests for direct/component separation, persisted component quantity, current names, blank codes, and zero-quantity component-only owner parents without duplicated rows.
- [x] 1.2 Implement revenue classification for known POS allocation, standard non-POS included components, standalone commercial records, and uncertain historical records; verify writer-shaped fixtures distinguish informational prices from assigned values and ambiguous rows show unavailable without catalog-price substitution.
- [x] 1.3 Project exact POS residual parent revenue from all sibling assigned subtotals before catalog filters, with origin-appropriate tax treatment; verify 125000 parent/25000 child yields 100000 parent + 25000 child, multi-owner allocations conserve totals, rounding stays exact, included/exclusive tax cases work, and ATK-only filtering does not change sibling decomposition.
- [x] 1.4 Preserve effective reporting dates, fulfilled eligibility, authorized sale business scope, customer/tags, settlement-reduced persisted values, and existing document-discount behavior in both projection branches; verify focused regression fixtures exclude partial dispatch/unselected businesses and do not double-subtract returns or add replacement HPP.
- [x] 1.5 Add report dataset/attribution notes to a focused sales-by-product guide; verify the guide explains known assigned, included, standalone, and ambiguous historical revenue with examples matching fixtures.

## 2. Snapshot modal and completeness

- [x] 2.1 Add shared read-side HPP/provenance interpretation for detail and bundle snapshots using included quantities; verify weighted multi-business HPP (2×100 + 3×200 = 800; average 160), own costs counted once, settlement-reduced quantities, verified total-only snapshots, and incompatible units kept separate.
- [x] 2.2 Implement complete/partial HPP state and unavailable averages; verify valid numeric zero stays complete, missing timestamps/values and MISSING_AVERAGE_PRICE remain incomplete, genuine-zero-as-missing is not reclassified, historical valid backfill/import sources are preserved, and no current purchase-price lookup or snapshot write occurs.
- [x] 2.3 Implement grand totals over all filtered rows plus known/partial revenue state and quantity-weighted selling averages; verify mixed known/unassigned component revenue suppresses average selling price, complete zero quantities are safe, partial modal suppresses average HPP, pagination does not change totals, and no totals sum averages or unlike-unit quantities.
- [x] 2.4 Document modal provenance and valid-zero limitations in the report guide; verify displayed/exported examples distinguish partial cost, Included in bundle, and unavailable revenue without implying net profit.

## 3. Filters and checkbox selection

- [x] 3.1 Extend filter DTO, validation, hashing, apply/reset/cancel, and snapshot paths with brand IDs and category descendant semantics; verify OR within category/brand/product selections, AND across filters, ATK-only generation with empty product IDs, overlapping category branches without duplication, and global master-data lookups.
- [x] 3.2 Adapt business-checkbox-selector and category/brand/product checkbox dropdown interaction from SoldSerialNumberReport; verify authorized active-business default, server sanitization, existing empty-business fallback, global tokenized name/code search, 25-result notice, checked selection persistence, three-chip expand/collapse, clear/remove, and existing explicit 500-product bulk-selection behavior.
- [x] 3.3 Add pending-filter feedback and applied-result stability for every selector; verify Livewire tests keep previous rows until Apply, block export after changes including brand/business changes, and preserve access denial and customer/tag behavior.
- [x] 3.4 Update the report Blade table and report entry description with Dijual sebagai, Total Modal, and Modal Rata-rata/unit and consistent numeric/status rendering; verify focused rendering tests and a browser smoke check of dropdown keyboard/outside-click behavior, descendant labels, status markers, and direct/component rows.
- [x] 3.5 Document filter examples and mixed-business weighted averages in the report guide; verify ATK plus brand/product and multiple-business examples agree with automated fixtures.

## 4. Excel and CSV parity

- [x] 4.1 Extend SaleByProductReportExport using the same normalized report dataset and totals, updating headings, numeric formats, metadata widths, and status fields; verify XLSX/CSV contain all pages in deterministic order, complete zeros remain numeric, unavailable averages are blank with reasons, and partial HPP/revenue markers match preview.
- [x] 4.2 Extend export guard regression tests for categories, descendants, brands, products, and authorized scope; verify changed filters refuse both exports until Apply and unchanged filters produce the same rows/totals including bundle and incomplete-cost cases.
- [x] 4.3 Add screen/export field mapping and a sample ATK export explanation to the report guide; verify field order and completeness meanings against generated fixture exports.

## 5. Focused integration verification

- [x] 5.1 Run the affected SaleByProductReport feature tests and any newly introduced projection/completeness/export test classes with explicit `php artisan test` paths/filters on isolated SQLite; record commands/results and broaden only when failures or identified regressions justify it.
- [x] 5.2 Verify bounded query growth and production-engine arithmetic using targeted fixtures: compare query count for small/large result pages and run a focused isolated MySQL check of component pre-aggregation, decimal residuals, role grouping, and category descendants; record coverage without using or mutating the working database.
- [x] 5.3 Complete an ATK-only and multi-business browser/export smoke check covering direct sales, assigned POS components, non-POS included components, valid zero, and missing HPP; record outcomes and fixture-only coverage for cases absent locally, confirm no schema/transaction changes, and rerun strict OpenSpec validation.

## Workflow follow-up

- Review the implementation and archive this change after focused verification and acceptance.
- Treat future genuine-zero acquisition snapshot capture and historical revenue repairs as separate work.
