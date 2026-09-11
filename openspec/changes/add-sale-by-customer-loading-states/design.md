## Context

The report is a single Livewire component (`SaleByCustomerReport`) rendered by one Blade view. It already uses a few `wire:loading` directives (Filter button spinner, export button spinners, autocomplete field spinners) but nothing dims or blocks the table, and the export dropdown's per-item spinners disappear when the dropdown menu closes on click.

## Goals / Non-Goals

**Goals:**

- Give clear, low-flicker feedback during `applyFilters`.
- Give clear feedback during `exportExcel` / `exportCsv` on the one button that stays visible (the dropdown trigger).
- Prevent duplicate filter/export submissions while a request is in flight.
- Keep autocomplete loading behavior exactly as it is today.

**Non-Goals:**

- No new Alpine/JS state machine; Livewire's built-in `wire:loading` directives are sufficient.
- No change to pagination, sorting, drawer, or snapshot-validation behavior.
- No change to export file generation or query logic (see `fix-sale-by-customer-export-invalid-date`).

## Decisions

1. **Use `wire:loading.delay` for the table overlay**, scoped to `wire:target="applyFilters"`, so a fast response does not flash an overlay.
2. **Dim + block only for `applyFilters`.** The table wrapper gets `wire:loading.class="opacity-50"` and a scoped `pointer-events: none` class, both targeted to `applyFilters` only, so unrelated Livewire requests (pagination, sorting, autocomplete) never dim or block the table.
3. **Export loading lives entirely on the dropdown trigger button**, since the dropdown menu (and its per-item spinners) closes immediately after a click. The trigger swaps its download icon for a spinner and gains `visually-hidden` loading text, targeted to `exportExcel,exportCsv`.
4. **Disable, don't hide, the export actions while loading** so the dropdown affordance stays visually stable; `wire:loading.attr="disabled"` on the dropdown trigger and both export items prevents duplicate downloads.
5. **No new JS state.** All behavior is expressible with existing `wire:loading`/`wire:target` directives plus one small scoped CSS class for `pointer-events: none`.

## Risks / Trade-offs

- **[`wire:loading.delay` default threshold may feel slow/fast]** → Use Livewire's default delay (no custom timing) unless manual testing shows it needs adjustment; this stays a human verification item.
- **[Scoped `pointer-events: none` must not leak to other tables/pages]** → Apply it only via a directive-toggled class on this view's table wrapper, not a global stylesheet rule.

## Migration Plan

No data or route changes. Deploy the view change alone; rollback is a straight revert of the Blade file.

## Open Questions

None.
