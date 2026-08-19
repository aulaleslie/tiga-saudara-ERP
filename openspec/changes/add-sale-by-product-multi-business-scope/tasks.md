## 1. Unscope master-data filter lookups

- [x] 1.1 Remove `->where('setting_id', $this->settingId)` from `updatedProductSearch()` in `app/Livewire/Reports/SaleByProductReport.php` (line ~189), keeping the 2-character minimum and `limit(10)`
- [x] 1.2 Remove `->where('setting_id', $this->settingId)` from `updatedCategorySearch()` in the same file (line ~176), keeping the 2-character minimum and `limit(10)`
- [x] 1.3 Confirm `updatedCustomerSearch()` (line ~148) and `updatedTagSearch()` are already unscoped and require no change; record the finding rather than editing
- [ ] 1.4 Manually verify against local data that searching "alfa ink" under CV TIGA NUSA (`setting_id = 1`) now returns product `286` "ALFA INK L SERIES EPSON BLACK"

## 2. Make the filter scope plural and normalized

- [x] 2.1 In `app/Services/Reports/SaleByProductReportFilterData.php`, replace `public ?int $scopeSettingId = null` with `public array $scopeSettingIds = []`
- [x] 2.2 Normalize `scopeSettingIds` in the constructor: cast each entry to `int`, drop non-positive values, `sort()` ascending, then `array_values()` to reindex
- [x] 2.3 Update `toArray()` to emit the plural `scopeSettingIds` key
- [x] 2.4 Update `fromArray()` to read `scopeSettingIds`, and to tolerate a legacy scalar `scopeSettingId` by promoting it to a single-element array (session-persisted snapshots may still carry the old key)
- [x] 2.5 Confirm `hash()` needs no change once normalization is in the constructor

## 3. Scope report queries by the selected settings

- [x] 3.1 In `app/Services/Reports/SaleByProductReportQueryService.php`, replace `$scopeSettingId = $filter->scopeSettingId ?: session('setting_id')` with a plural resolution that falls back to `[(int) session('setting_id')]` when the array is empty
- [x] 3.2 Change `->where('sales.setting_id', $scopeSettingId)` to `->whereIn('sales.setting_id', $scopeSettingIds)`
- [x] 3.3 Change `->where('sale_returns.setting_id', $scopeSettingId)` to `->whereIn('sale_returns.setting_id', $scopeSettingIds)`
- [x] 3.4 Verify no other query path in the service relies on a scalar scope

## 4. Validation and snapshot persistence

- [x] 4.1 Add a `scopeSettingIds` rule to `app/Services/Reports/SaleByProductReportValidator.php`: `nullable|array` plus an `integer` element rule (no rule exists today)
- [x] 4.2 In `app/Services/Reports/SaleByProductReportSnapshot.php`, change `?int $scopeSettingId` to `array $scopeSettingIds`, updating `toArray()` and `fromArray()`; accept the legacy scalar key on read
- [x] 4.3 In `SaleByProductReportSnapshotService::createSnapshot()`, persist `$filter->scopeSettingIds`
- [x] 4.4 Confirm `isValidForExport()` still compares hashes only and needs no further change

## 5. Adopt the shared scope trait in the Livewire component

- [x] 5.1 Add `use HasReportSettingScope;` to `app/Livewire/Reports/SaleByProductReport.php` and remove the scalar `public $settingId` property
- [x] 5.2 In `mount()`, replace `$this->settingId = session('setting_id')` with `$this->selectedSettingIds = []` so empty selection falls back to the session setting
- [x] 5.3 Load available settings in `render()` and pass `availableSettings`, `selectedSettingIds`, and `getScopeLabel(...)` to the view
- [x] 5.4 Replace all four `$filter->scopeSettingId = $this->settingId` assignments with `$filter->scopeSettingIds = $this->validateSettingIds($this->getEffectiveSettingIds(), $availableSettings)`, ensuring the export path is validated the same way as the render path (the trait's `getEffectiveSettingIds()` alone does not reindex)
- [x] 5.5 Reset pagination when the selected scope changes

## 6. Export company name and view wiring

- [x] 6.1 In `app/Exports/SaleByProductReportExport.php` (line ~105), replace `Setting::find($this->filter->scopeSettingId)` with logic that resolves a single company name when exactly one setting is selected, and an appropriate multi-company label otherwise
- [x] 6.2 Include the `livewire.reports.business-source-selector` partial in `resources/views/livewire/reports/sale-by-product-report.blade.php`, passing `selectId` = `saleByProductSettingIds`, `availableSettings`, `livewireProperty` = `selectedSettingIds`, and `selectedValues`
- [ ] 6.3 Place the selector to match the profit-loss layout and confirm the Select2 styling renders identically
- [ ] 6.4 Verify the selector re-initialises correctly after a Livewire re-render and on filter reset

## 7. Tests

- [x] 7.1 Add a test asserting default (empty) scope produces the same rows as the current-setting behaviour
- [x] 7.2 Add a test asserting a multi-setting scope combines sale rows across settings, and excludes unselected settings
- [x] 7.3 Add a test asserting returns are scoped by `sale_returns.setting_id` across the same selected set
- [x] 7.4 Add a test asserting the same product sold in two selected settings merges into one aggregate row
- [x] 7.5 Add hash-stability tests: reversed selection order, string-vs-int identifiers, and key-gapped arrays all produce an identical filter hash
- [x] 7.6 Add a test asserting a changed company scope invalidates the export snapshot
- [x] 7.7 Add a test asserting the product filter offers a product whose `products.setting_id` differs from the sale's setting
- [x] 7.8 Add a test asserting duplicate category names across settings are listed as separate options
- [x] 7.9 Add a test asserting invalid or unavailable setting identifiers are discarded before querying
- [x] 7.10 Run `Modules/Reports/Tests/Feature/SaleByProductReportTest.php` and `tests/Feature/Livewire/Reports/SaleByProductEffectiveDateTest.php` and fix regressions

## 8. Verification

- [x] 8.1 Run `composer test:fresh-sqlite` or a focused `php artisan test` filter and confirm the suite passes
- [ ] 8.2 Manually verify the report at `/reports/sale-by-product` with a single business selected, matching pre-change output
- [ ] 8.3 Manually verify multi-business selection, the scope label (`Semua Perusahaan` when all are selected), and Excel/CSV export
- [x] 8.4 Confirm no database migration was added and no unrelated report changed behaviour
