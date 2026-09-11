## 1. Filter Loading State

- [x] 1.1 Add a `position-relative` wrapper around the report table with a `wire:loading.class="opacity-50"` and scoped `pointer-events: none` style, both targeted only to `applyFilters`.
- [x] 1.2 Add a centered `wire:loading.delay` overlay spinner above the table, targeted only to `applyFilters`, with `visually-hidden` loading text.
- [x] 1.3 Ensure both Filter buttons (inline and drawer) show a spinner and are disabled while `applyFilters` is in progress, with `visually-hidden` loading text.

## 2. Export Loading State

- [x] 2.1 Add a spinner + `visually-hidden` loading text to the Ekspor dropdown trigger, targeted to `exportExcel,exportCsv`, replacing its normal icon while loading.
- [x] 2.2 Ensure the dropdown trigger and both export actions are disabled while either export action is in progress, without dimming the table.

## 3. Verification

- [x] 3.1 Run `php artisan test --filter=SaleByCustomer`.
- [x] 3.2 Add focused Livewire rendering assertions only if needed to confirm the new markup/attributes render; no browser test suite.
- [ ] 3.3 Record human verification of the visual spinner/dim/disable behavior and XLSX/CSV download completion as the remaining manual acceptance step.
