## 1. Shared Business Selector

- [x] 1.1 Generalize the Laporan Laba Rugi business-source selector into a configurable Select2/CoreUI multi-select that accepts a unique element ID, Livewire property name, current selected values, ordered setting options, and all-businesses placeholder while preserving the report's existing defaults.
- [x] 1.2 Make selector initialization and teardown idempotent across Livewire renders, prevent duplicate change handlers and feedback loops, and synchronize programmatic Livewire state changes into the `wire:ignore` Select2 display.
- [x] 1.3 Update or add focused report component coverage confirming Laporan Laba Rugi still renders and binds the shared selector through `selectedSettingIds` without changing its empty-selection semantics.

## 2. Global Payment Integration

- [x] 2.1 Provide ordered minimal business option data to the Global Sales Payment and Global Purchase Payment table views without querying `Setting::all()` from Blade.
- [x] 2.2 Replace the native Global Sales Payment business list box with the shared selector bound to `draftGlobalBusinessFilters`, seeded from restored state, while preserving explicit `Terapkan Filter` behavior and empty-as-all semantics.
- [x] 2.3 Replace the native Global Purchase Payment business list box with the shared selector bound to `draftGlobalBusinessFilters`, seeded from restored state, while preserving explicit `Terapkan Filter` behavior and empty-as-all semantics.
- [x] 2.4 Add the minimal Livewire-to-browser synchronization needed for `Reset semua filter` and URL hydration to visibly clear or restore each Global Payment selector without changing the applied filter and summary-card event payloads.

## 3. Verification

- [x] 3.1 Extend Global Sales Payment feature/component tests to verify searchable styled selector markup, multiple draft selections, no premature application, URL-restored selections, visible-reset synchronization signals, and unchanged table/summary results after apply and reset.
- [x] 3.2 Extend Global Purchase Payment feature/component tests to verify searchable styled selector markup, multiple draft selections, no premature application, URL-restored selections, visible-reset synchronization signals, and unchanged table/summary results after apply and reset.
- [x] 3.3 Run the focused Profit Loss Report, Global Sale Payment filter, Global Sale Payment table, Global Purchase Payment filter, and Global Purchase Payment table test suites and resolve regressions attributable to this change.
- [x] 3.4 Perform a browser smoke check on both Global Payment pages for search, removable choices, responsive layout, apply, refresh restoration, reset, and repeated Livewire interactions without duplicated events.
