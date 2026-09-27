## Why

The "Laporan" sidebar entry is currently a deeply nested dropdown tree (Laporan → Pembelian/Penjualan sub-dropdowns → individual reports). As the number of reports grows, the nested sidebar becomes hard to scan, easy to mis-click, and offers no overview of what reports exist. A dedicated landing page with categorized tabs and report cards gives users a clear, discoverable entry point while keeping the existing report pages unchanged.

## What Changes

- Add a new `/reports` landing page reached by clicking "Laporan" in the sidebar.
- The landing page presents tabbed categories: **Laba/Rugi**, **Penjualan**, **Pembelian**, **Stock**, and **Lainnya** (utilities).
- The active tab is selected via a `?tab=` query parameter; each tab renders a grid of report cards.
- Each card links to an existing report route (no report page behavior changes).
- Tabs and cards are filtered by the current user's permissions, mirroring the existing `@can`/`@canany` gates exactly. Empty tabs are hidden; the default tab is the first one the user can actually see.
- **BREAKING** (navigation only): The "Laporan" sidebar dropdown and its nested sub-dropdowns are removed and replaced by a single link to `/reports`. Mekari Converter, Mekari Invoice Generator, and all report routes remain unchanged — only the navigation path to reach them changes.

## Capabilities

### New Capabilities
- `reports-landing-navigation`: A categorized landing page for reports with permission-aware tabs and cards, query-param tab selection, and a single sidebar entry point replacing the nested dropdown tree.

### Modified Capabilities
<!-- No existing spec defines report navigation/menu behavior; nothing to modify. -->

## Impact

- **New route/controller**: `reports.index` → `ReportsController@index` in the Reports module, gated by the same umbrella `@canany([...])` permission set the sidebar uses today.
- **New Blade view**: a single landing view rendering tab navigation and the card grid.
- **Sidebar**: `resources/views/layouts/menu.blade.php` Laporan block (the `@canany` tree) collapses to one link to `reports.index`.
- **No changes** to existing report routes, controllers, Livewire components, or report views.
- **Permissions**: reuses `reports.access`, `saleReports.access`, `saleReports.global.access`, `purchaseReports.access`, `purchaseReports.global.access`, `stockMutationReports.access`, `stockMutationReports.global.access`, `inventoryValuationReports.access`.
