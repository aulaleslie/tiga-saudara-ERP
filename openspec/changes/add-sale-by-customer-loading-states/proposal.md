## Why

The Penjualan Per Customer report gives no visual feedback while `applyFilters`, `exportExcel`, or `exportCsv` are running. Users can click Filter or Ekspor multiple times, and the table appears interactive (rows can be clicked, pagination can be used) while a filter request is still in flight, which can trigger duplicate exports or confusing intermediate states.

## What Changes

- Show a spinner and disable both Filter buttons while `applyFilters` runs; dim the report table and block pointer interaction with it, using a centered delayed spinner overlay to avoid flicker on fast responses.
- Show a spinner on the always-visible Ekspor dropdown button while `exportExcel` or `exportCsv` runs (the dropdown menu itself closes on selection, so the trigger button is the only element that stays visible); disable the dropdown and both export actions to prevent duplicate downloads. Do not dim the table during export since export does not replace table contents.
- Leave the existing customer/category/tag autocomplete field-level spinners untouched; they must not dim or disable the table.
- Scope all new `wire:loading` directives with explicit `wire:target` values so unrelated Livewire requests (autocomplete searches, sorting, pagination) do not trigger the new loading states.
- Add `visually-hidden` accessible loading text alongside each new spinner.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `sales-by-customer-report`: Add loading-state requirements for filter apply and export actions on the Penjualan Per Customer screen.

## Impact

- Affected view: `resources/views/livewire/reports/sale-by-customer-report.blade.php`.
- No backend, query, export-generation, route, or permission changes.
- Visual/interaction change only; existing filter, snapshot-validation, export, and drawer behavior is preserved.
