## 1. On-Screen Table
 
- [x] 1.1 In `cross-business-stock-inventory.blade.php`, add two `<th rowspan="2">` header cells ("Total Bagus", "Total Rusak") in the Tier 1 header row, immediately after the Merek `<th>` and before the business loop.
- [x] 1.2 In the same view's tbody, add two `<td>` cells immediately after the Merek `<td>`, rendering `$product['total_good']` and `$product['total_bad']` as floats, following the existing styling convention used for Good/Bad cells (bold when > 0, muted when 0).
- [x] 1.3 Update the empty-state row's `colspan` from `3 + max(1, count($businesses) * 2)` to `5 + max(1, count($businesses) * 2)`.

## 2. Excel Export
 
- [x] 2.1 In `CrossBusinessStockInventoryExport::array()`, add "Total Bagus" and "Total Rusak" to `$headerRow2` (with corresponding empty-string placeholders in `$headerRow3`/`$headerRow4`) immediately after "Merek", and shift the business loop's starting `$colIdx` from 4 to 6.
- [x] 2.2 Add `columnMaxWidths` tracking for the two new columns (keys 4 and 5), sized from header label length and formatted numeric values.
- [x] 2.3 In the data-row loop, insert `$product['total_good']` and `$product['total_bad']` into `$row` immediately after `$brandStr`, and shift the business loop's starting `$cIdx` from 4 to 6.
- [x] 2.4 In `CrossBusinessStockInventoryExport::styles()`, add vertical merges `D2:D4` and `E2:E4` for the two new columns (matching the existing A/B/C pattern), and shift the business/location merge loop's starting `$colIndex` from 4 to 6.

## 3. Verification (focused, no full suite)

- [x] 3.1 Locate any existing feature/unit test covering this report or export (e.g. filter for `CrossBusinessStockInventory`); run it via `php artisan test --filter=CrossBusinessStockInventory` and update any assertion tied to column count/position or `lastColumnLetter` to account for the 2 new columns.
- [x] 3.2 Manually load the report in the browser with 2+ selected businesses (at least one with 2+ locations): confirm "Total Bagus"/"Total Rusak" appear right after Merek, values equal the sum of that row's visible business Good/Bad values, and toggling expand/collapse on a business does not change the totals.
- [x] 3.3 Manually trigger the Excel export with the same filter state, open the file, and confirm the two total columns appear in the correct position with correct merges and values matching the on-screen totals.
