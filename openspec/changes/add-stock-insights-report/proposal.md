# Proposal

## Why

Store owners and operational staff need one understandable place to see current global stock, recent product sales and profitability, and products that need attention without interpreting multiple inventory reports or advanced inventory terminology. The ERP already holds cross-business stock buckets, global minimum-stock values, current settlement-adjusted sales details, and sales cost snapshots, but does not combine them into a simple operational view.

## What Changes

- Add a permission-gated `Pantauan Stok` report card under `Laporan → Produk` without replacing any existing report.
- Show active stock-managed products with a sortable current global Good-stock quantity summed across all businesses and active locations; Broken stock and tax/non-tax composition remain informational only.
- Let users expand the global stock column into separate business columns and independently expand each business column into separate location columns, following the established Stok Lintas Bisnis grouped-column interaction while keeping the sortable global column visible.
- Show actionable Indonesian statuses for `Stok Habis`, `Perlu Dibeli Lagi`, `Batas Minimum Belum Diatur`, and `Lama Tidak Terjual` using a product-wide global minimum-stock threshold.
- Allow an authorized user to update only the global minimum-stock value from a focused modal beside the product name; every other Stock Insights operation remains read-only.
- Add sortable recent-sales columns for quantity sold, tax-exclusive sales value, snapshot-based cost of goods sold, gross profit, and last sale, using current persisted eligible Sale/SaleDetail values without independently subtracting Sales Return records.
- Default sales metrics to the inclusive last seven calendar days through today; support 7/30/90-day presets and a user-selected non-future start date whose inclusive duration is reflected by a system-generated period label.
- Add product search plus attention-status, category, and brand filters. Business and location are drill-down columns rather than filters because the stock threshold and status are global.
- Use focused automated verification only. Browser acceptance is a human activity; implementation work does not include full-suite execution, browser automation, Chrome, Playwright, Selenium, or equivalent browser commands.

## Capabilities

### New Capabilities

- `stock-insights-report`: Permission-gated current-stock and recent-sales operational report, global/business/location stock expansion, attention rules, financial measures, filtering/sorting, and inline global minimum-stock maintenance.

### Modified Capabilities

None.

## Impact

- Reports landing configuration gains a new Produk card and dedicated permission.
- The Reports module gains a new route/controller or Livewire page, stock/sales aggregation services, Indonesian views, and focused feature/unit tests.
- Product minimum-stock persistence is updated only if needed to align its precision with supported inventory quantities; existing stock and transaction lifecycle behavior remains unchanged.
- Existing `Ringkasan persediaan barang`, `Stok Persediaan Lintas Bisnis`, sale, return, product, stock-transfer, and inventory reports remain available and behaviorally unchanged.
