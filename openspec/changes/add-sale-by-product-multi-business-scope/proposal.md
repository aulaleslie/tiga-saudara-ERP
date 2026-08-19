## Why

The Sale by Product report (`/reports/sale-by-product`) scopes its filter option lookups by `setting_id` on master data (products, categories, customers), but scopes its report rows by `setting_id` on transactions (`sales`, `sale_returns`). These two scopes disagree, so users can see rows they cannot filter to. Concretely: sale `203864` belongs to CV TIGA NUSA (`setting_id = 1`) and contains product `286` "ALFA INK L SERIES EPSON BLACK", whose `products.setting_id = 6` (PERDANA). The line appears in the CV TIGA NUSA report, but "ALFA INK" can never be found in the product search filter.

This is not an isolated record: 50,439 of 412,866 sale lines (12.2%) reference a product master owned by a different setting than the sale. The underlying reason is that `setting_id` on master data records where a row was *created*, not who *owns* it — real product ownership lives in `product_stocks` → `locations.setting_id` (457 products hold stock spanning more than one setting; product `286` holds its entire 265-unit stock in setting 1 while its row says setting 6). Additionally, the report is restricted to a single business at a time, so cross-business sales activity cannot be reviewed together.

## What Changes

- Add a multi-select business/company scope filter to the Sale by Product report, reusing the existing `HasReportSettingScope` trait and `business-source-selector` Select2 partial already used by `/profit-loss-report` and the operational reports, matching their styling and multi-select behaviour.
- Report rows SHALL be scoped to the selected settings via `sales.setting_id` and `sale_returns.setting_id`. Empty selection preserves today's behaviour (current `session('setting_id')`).
- **BREAKING** (spec-level): Remove `setting_id` scoping from the product and category filter option lookups. Master data is treated as unscoped; only transactions carry business ownership. The customer and tag lookups are already unscoped in code and are unchanged, but the specification is updated to state that master-data options must not be setting-scoped, so the intent is pinned rather than incidental.
- Normalize the setting-scope array (integer cast, sort, reindex) before it is hashed, so that selection order, value type, and array key gaps cannot false-trigger the export filter-drift guard.
- Add the missing validation rule for the setting-scope array, which has no rule today.
- Duplicate category names across settings (e.g. four distinct `LAPTOP` categories) SHALL be listed as-is without name-based deduplication, so the dropdown honestly reflects the data.

Deliberately out of scope: the same `products.setting_id` predicate used by POS search, sales carts, and purchase forms. Those are left untouched and noted as possible follow-up work.

## Capabilities

### New Capabilities
- `sale-by-product-report-setting-scope`: Selectable multi-business scope for the Sale by Product report, covering scope selection, default scope, row scoping across sales and returns, scope labelling, and export-snapshot stability under scope selection.

### Modified Capabilities
- `sale-by-product-report`: Report rows are scoped to a user-selected set of settings rather than only the current setting; filter option lookups for master data (product, category, customer) are no longer scoped by `setting_id`.

## Impact

Affected code:
- `app/Livewire/Reports/SaleByProductReport.php` — adopt `HasReportSettingScope`, replace scalar `$settingId`, unscope `updatedProductSearch` and `updatedCategorySearch` (the only two `setting_id` predicates present)
- `app/Services/Reports/SaleByProductReportFilterData.php` — `scopeSettingId: ?int` → `scopeSettingIds: array`, normalization in constructor, `toArray`/`fromArray`/`hash`
- `app/Services/Reports/SaleByProductReportQueryService.php` — both `sales.setting_id` and `sale_returns.setting_id` predicates become set membership
- `app/Services/Reports/SaleByProductReportValidator.php` — add scope array validation rule
- `app/Services/Reports/SaleByProductReportSnapshot.php` and `SaleByProductReportSnapshotService.php` — persist plural scope
- `app/Exports/SaleByProductReportExport.php` — plural scope through export filters
- `Modules/Reports/Resources/views/sale-by-product/index.blade.php` and the Livewire view — include `business-source-selector`

Reused without modification: `app/Livewire/Reports/HasReportSettingScope.php`, `resources/views/livewire/reports/business-source-selector.blade.php`.

Tests: `Modules/Reports/Tests/Feature/SaleByProductReportTest.php`, `tests/Feature/Livewire/Reports/SaleByProductEffectiveDateTest.php`.

No database migrations. No changes to how sales, returns, or products are stored.
