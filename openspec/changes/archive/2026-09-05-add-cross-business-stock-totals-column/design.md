## Context

`CrossBusinessStockInventoryQueryService::buildRows()` already returns, per product row: `total_good` and `total_bad` (floats), computed from `$stockMatrix[$productId]['total_good'|'total_bad']`, which itself is summed in `getStockMatrix()` only over `$filters->businessIds` (the currently selected/visible businesses). This value is already correct regardless of per-business collapse/expand UI state, since collapse/expand only affects how a business's own Good/Bad is broken into locations, not the cross-business total.

Two consumers render this row data:
1. `resources/views/livewire/reports/cross-business-stock-inventory.blade.php` — two-tier sticky header + scrollable table, columns: Produk (sticky) | Kategori | Merek | [per-business Good/Bad or per-location Good/Bad ...].
2. `app/Exports/CrossBusinessStockInventoryExport.php` — 4-row header (title, business, location, Good/Bad), fixed columns A-C (Produk/Kategori/Merek), business/location columns starting at column D (colIdx = 4), always expanded to per-location regardless of on-screen collapse state.

## Goals / Non-Goals

**Goals:**
- Render `total_good`/`total_bad` as two new columns immediately after Merek, on-screen and in the export.
- Keep the total columns stable regardless of any business's expand/collapse toggle state (they already are, since they come from a separate pre-computed field, not from the business loop).

**Non-Goals:**
- No change to `total_good`/`total_bad` computation — already correct.
- No change to permissions, filters, pagination, or search.
- No change to the serial-number dialog.

## Decisions

- **Column placement**: fixed columns, not part of the `@foreach($businesses as $b)` loop, since they're business-independent aggregates. Positioned after Merek and before the business loop, both on screen and in export.
- **Header styling (screen)**: reuse the existing `rowspan="2"` pattern used by Kategori/Merek `<th>` (single-tier header spanning both header rows), not a new business-style two-tier group — these are plain aggregate columns, not per-business groups.
- **Header styling (export)**: reuse the existing `A2:A4`/`B2:B4`/`C2:C4` vertical-merge pattern for Produk/Kategori/Merek. New columns D ("Total Bagus") and E ("Total Rusak") get the same `D2:D4` / `E2:E4` merge. All business/location columns shift right by 2 (colIdx starts at 6 instead of 4).
- **Export column-index bookkeeping**: `columnMaxWidths` keys 1-3 are hardcoded (Produk/Kategori/Merek); add keys 4-5 for the new columns, and shift the business loop's starting `$colIdx`/`$cIdx` from 4 to 6 in both `array()` and `styles()`. `getColumnLetter()` is index-based and needs no logic changes, only different starting indices.
- **Empty-state colspan (screen)**: the "no products match" row's `colspan` (`3 + max(1, count($businesses) * 2)`) becomes `5 + max(1, count($businesses) * 2)`.
- **Verification approach**: per project convention, avoid full-suite test runs; verify with targeted focused checks — a Livewire component test or feature test scoped to this report page (rendering + Excel export), run via `php artisan test --filter=CrossBusinessStockInventory`, plus manual browser check of the on-screen table (column position, correct sums, collapse/expand doesn't affect totals) and downloading/opening the exported Excel file to confirm column position and merges.

## Risks / Trade-offs

- [Off-by-one in shifted export column indices breaks merges or misaligns Good/Bad values under the wrong business/location header] → Mitigate by shifting all three loop-dependent counters (`colIdx` in `array()`, `colIndex` in `styles()`, `cIdx` in the data-row loop) together and manually verifying one exported file with 2+ businesses and a business with 2+ locations.
- [Existing tests/snapshots asserting the export's column count or letter (e.g., `lastColumnLetter`) may need updating] → Locate and run any existing feature test for this export/report before/after the change as the focused verification step.
