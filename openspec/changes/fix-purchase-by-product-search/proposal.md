# Proposal

## Why

The purchase by product report searches products within a setting even though products are global. Selecting a suggestion also erases the search and its results, forcing users to repeat the same search to select another product.

## What Changes

- Search the global product catalog without a `setting_id` condition.
- Collapse suggestions after selection while retaining the search term and results for the next focus.
- Keep an already selected product visible but unavailable in suggestions, and retain only one selected entry per product ID.
- Preserve setting based scoping of purchase data in the report.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `purchase-by-product-report`: Clarify product suggestion scope and repeated selection behavior in the report filter.

## Impact

- `app/Livewire/Reports/PurchaseByProductReport.php` and `resources/views/livewire/reports/purchase-by-product-report.blade.php`.
- Focused Livewire and view verification in `Modules/Reports/Tests/Feature/PurchaseByProductReportTest.php` or the nearest existing report test.
- No schema, API, or dependency changes.
