## 1. Template Structure Refactoring

- [x] 1.1 Remove all conditional column rendering from `<thead>` - keep header structure static
- [x] 1.2 Convert `<tbody>` cell rendering to use conditional content instead of conditional cell count
- [x] 1.3 Add "Aktivitas Terakhir" column to header (always present)
- [x] 1.4 Ensure every `<tr>` in tbody contains exactly 12 `<td>` elements

## 2. Status-Specific Cell Content

- [x] 2.1 Update cash column (position 8) to show `expected_cash_total` for OPEN, `counted_cash_total` for CLOSED
- [x] 2.2 Update metrics column (position 10) to show transaction count badge for OPEN, variance for CLOSED
- [x] 2.3 Add conditional placeholders (`-`) for non-applicable cells (e.g., Trx for closed sessions)
- [x] 2.4 Update "Aktivitas Terakhir" cell to show time for OPEN sessions, `-` for closed

## 3. Styling and Layout

- [x] 3.1 Apply fixed column widths or table-layout CSS to prevent reflow when switching filters
- [x] 3.2 Add consistent text-align CSS classes (text-end for numeric columns)
- [x] 3.3 Ensure placeholder dashes (`-`) are styled consistently (muted appearance)
- [x] 3.4 Test table appearance at different viewport widths

## 4. Testing and Verification

- [x] 4.1 View table with no status filter (mixed OPEN and CLOSED sessions)
- [x] 4.2 View table filtered by OPEN status only
- [x] 4.3 View table filtered by CLOSED status only
- [x] 4.4 Verify column headers align with data cells in all views
- [x] 4.5 Verify pagination maintains consistent table structure
- [x] 4.6 Verify no JavaScript errors or console warnings
- [x] 4.7 Test on mobile viewports to ensure responsive behavior

## 5. Code Review and Cleanup

- [x] 5.1 Review template for any remaining conditional column logic
- [x] 5.2 Remove unused conditional variables or simplify logic
- [x] 5.3 Add comments explaining status-specific cell rendering
- [x] 5.4 Ensure code follows project conventions and style guidelines
