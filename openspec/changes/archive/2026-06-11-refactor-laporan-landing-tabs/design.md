## Context

The Reports module exposes ~10 report pages, currently reachable only through a deeply nested "Laporan" sidebar dropdown in `resources/views/layouts/menu.blade.php` (the `@canany` block spanning the Laporan section). Each entry is individually permission-gated with `@can`/`@canany`, and two sub-dropdowns (Pembelian, Penjualan) add a second nesting level. There is no overview page; reports are discoverable only by expanding the sidebar tree.

This change introduces a server-rendered landing page at `reports.index` with permission-aware category tabs and report cards, and collapses the sidebar to a single link. No report page (route, controller, Livewire component, or view) changes behavior — only the path to reach them changes.

## Goals / Non-Goals

**Goals:**
- A single `/reports` landing page with tabs: Laba/Rugi, Penjualan, Pembelian, Stock, Lainnya.
- Tabs and cards filtered by the user's existing report permissions, exactly mirroring current sidebar gates.
- Active tab selected via `?tab=` query param, defaulting to the first tab the user can see.
- Sidebar Laporan entry collapsed to one link; nested tree removed.
- Centralized, testable tab/card configuration.

**Non-Goals:**
- No changes to existing report pages, queries, exports, or routes.
- No breadcrumb / "back to Laporan" navigation on report pages.
- No new permissions; reuse the existing set.
- No Livewire/reactive tab switching — server-rendered Blade only.

## Decisions

### Decision: Centralized tab/card config in the controller

`ReportsController@index` builds a structured array of tabs, each with a slug, label, icon, and a list of cards. Each card carries `label`, `icon`, `route` (route name), and `permission` (the ability string). The controller filters cards via `Gate::allows($permission)`, drops tabs with zero visible cards, resolves the active tab from `?tab=`, and passes the filtered structure plus active slug to a single Blade view.

**Why over hardcoding in Blade:** A config array is reorderable, testable in isolation (assert which tabs/cards a given permission set yields), and keeps the permission mapping in one auditable place. Hardcoded `@can` blocks in Blade mirror today's sidebar but are harder to test and easy to drift.

**Alternative considered — Livewire component:** Rejected. Tab state is trivially expressible as a URL query param; reactive machinery adds cost without benefit for a static menu page, and a shareable/bookmarkable `?tab=` URL is a UX plus.

### Decision: Tab → card mapping mirrors current sidebar gates exactly

| Tab | Slug | Cards → route | Permission |
|-----|------|---------------|-----------|
| Laba/Rugi | `laba-rugi` | Laporan Laba Rugi → `profit-loss-report.index` | `reports.access` |
| Penjualan | `penjualan` | Daftar Penjualan → `reports.sale-report.index` | `saleReports.access` |
| | | Penjualan Per Customer → `reports.sale-by-customer.index` | `saleReports.access` |
| | | Penjualan Global → `reports.sale-report.global` | `saleReports.global.access` |
| Pembelian | `pembelian` | Daftar Pembelian → `reports.purchase-report.index` | `purchaseReports.access` |
| | | Pembelian Per Supplier → `reports.purchase-by-supplier.index` | `purchaseReports.access` |
| | | Pembelian Global → `reports.purchase-report.global` | `purchaseReports.global.access` |
| Stock | `stock` | Mutasi Stok → `reports.stock-mutation-report.index` | `stockMutationReports.access` |
| | | Mutasi Stok Global → `reports.stock-mutation-report.global` | `stockMutationReports.global.access` |
| | | Valuasi Stok → `reports.inventory-valuation-report.index` | `inventoryValuationReports.access` |
| Lainnya | `lainnya` | Mekari Converter → `reports.mekari-converter.index` | `reports.access` |
| | | Mekari Invoice Generator → `reports.mekari-invoice-generator.index` | `reports.access` |

**Why:** Faithful carry-over is the safest behavior; users see exactly the reports they could reach before. Cards reference routes by name and use `Route::has()` defensively, matching the existing `@if(Route::has(...))` guards.

### Decision: Default tab is the first *visible* tab, not a hardcoded one

After filtering, the active tab resolves to: the `?tab=` slug if it exists among visible tabs, otherwise the first visible tab. A sales-only user (no `reports.access`) must not land on an empty Laba/Rugi tab.

### Decision: Route gating uses the umbrella `canany`

`reports.index` is gated by the same `canany([...])` permission set the sidebar Laporan block uses today, so a user with no report permission cannot reach the page at all (and the sidebar link is hidden by the same check).

## Risks / Trade-offs

- **[Permission drift between config and old sidebar]** → Lock the mapping in the spec table and add a feature test asserting tab/card visibility per permission set (sales-only, purchase-global, no-access, all-access).
- **[Empty page if mapping logic is wrong]** → Default-tab fallback always picks a visible tab; test the no-`tab`-param and invalid-`tab` cases explicitly.
- **[Removing the sidebar tree breaks bookmarks/muscle memory]** → Acceptable per product decision; report routes themselves are unchanged so existing deep links still work. The landing page is the new discovery surface.
- **[Route::has guards]** → Keep defensive `Route::has()` checks so a card silently disappears rather than throwing if a route is absent in some environment.

## Migration Plan

1. Add `reports.index` route + `ReportsController@index` with the config array and filtering logic.
2. Add the landing Blade view (tab nav + card grid).
3. Replace the sidebar Laporan `@canany` block with a single link to `reports.index`.
4. Add feature tests for permission-based tab/card visibility and tab-param resolution.
5. Rollback: revert the menu blade change to restore the nested dropdown; the new route/view are additive and harmless if the menu still points at them.
