## 1. Route & controller

- [ ] 1.1 Add `reports.index` GET route in `Modules/Reports/Routes/web.php`, gated by the umbrella `canany` permission set (`reports.access`, `saleReports.access`, `saleReports.global.access`, `purchaseReports.access`, `purchaseReports.global.access`, `stockMutationReports.access`, `stockMutationReports.global.access`, `inventoryValuationReports.access`), pointing to `ReportsController@index`
- [ ] 1.2 Implement `ReportsController@index`: define the tab/card config array (slug, label, icon, cards with label/icon/route/permission) per the design mapping table
- [ ] 1.3 Filter cards via `Gate::allows($permission)` and `Route::has($route)`; drop tabs with zero visible cards
- [ ] 1.4 Resolve active tab from `?tab=`, falling back to the first visible tab when missing/unknown/unauthorized; pass filtered tabs + active slug to the view

## 2. Landing view

- [ ] 2.1 Create the reports landing Blade view rendering tab navigation (links carrying `?tab=<slug>`, active tab highlighted)
- [ ] 2.2 Render the card grid for the active tab; each card shows icon + label and links to its report route

## 3. Sidebar menu

- [ ] 3.1 Replace the Laporan `@canany` nested-dropdown block in `resources/views/layouts/menu.blade.php` with a single link to `reports.index`, keeping the same umbrella `@canany` visibility gate and active-route highlighting for `reports.index`

## 4. Tests

- [ ] 4.1 Feature test: all-permission user sees all five tabs with the full card set
- [ ] 4.2 Feature test: sales-only user (`saleReports.access`) sees only the Penjualan tab with Daftar Penjualan + Penjualan Per Customer, no Global card, no other tabs
- [ ] 4.3 Feature test: user with no report permission is denied the `reports.index` route
- [ ] 4.4 Feature test: tab resolution — explicit `?tab=` selects that tab; missing/invalid/unauthorized `tab` falls back to the first visible tab

## 5. Verification

- [ ] 5.1 Run focused report tests (`php artisan test` with filter) and confirm green
- [ ] 5.2 Manually verify sidebar shows a single Laporan link and the landing page navigates to each existing report unchanged
