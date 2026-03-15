## Context

The POS sessions index view (`Modules/Pos/Resources/views/session/index.blade.php`) uses conditional column rendering in both the table header (`<thead>`) and body (`<tbody>`). Different columns appear based on the session status filter (OPEN vs CLOSED), but the conditional logic is positioned inconsistently between header and body rows. This causes table data to render misaligned with column headers.

Current problematic structure:
- Columns 1-7 are static (Terminal through Total Penjualan)
- Column 8 conditionally renders as either "Kas Ekspektasi" (OPEN) or "Kas Akhir" (CLOSED)
- Columns 9-11 are mixed conditional rendering for Pengambilan Kas, and status-specific metrics
- This causes data in columns 8-11 to shift position depending on which rows are rendered

## Goals / Non-Goals

**Goals:**
- Create a consistent, normalized table structure that displays all columns for every session regardless of status
- Align column headers with data cells correctly
- Maintain all existing data and functionality (no loss of information)
- Improve readability by eliminating confusing column shifts

**Non-Goals:**
- Redesign the table layout beyond fixing alignment (card views, expansible rows, etc.)
- Change controller logic or data calculation
- Modify the database schema
- Add new capabilities to the sessions index beyond normalization

## Decisions

### Decision 1: Always Render All Columns (Option 1 approach)
**Choice:** Keep all columns visible at all times, showing `-` or placeholder values when data is not applicable.

**Rationale:**
- Simplest solution with minimal code changes
- No layout shifts or conditional rendering complexity
- Users can compare across statuses without reorienting
- Columns always stay in the same position

**Alternatives Considered:**
- Split into two separate tables (OPEN vs CLOSED) - adds complexity, prevents side-by-side comparison
- Detail row expansion / card view - requires major layout restructuring
- Dynamic column hiding with CSS/JS - still shows empty cells, confusing

### Decision 2: Standardized Column Order
**Choice:** Use a fixed set of 11 columns in this order:
1. Terminal
2. Kasir
3. Status
4. Dibuka
5. Ditutup
6. Saldo Awal
7. Total Penjualan
8. Kas (shows Ekspektasi for OPEN, Akhir for CLOSED)
9. Pengambilan Kas
10. Metrics (Trx for OPEN, Selisih for CLOSED)
11. Aktivitas Terakhir (OPEN only, else -)
12. Aksi

**Rationale:**
- Logical flow: identification → timestamp → numbers → actions
- Keeps transaction count and variance visible in same position
- All rows have identical structure

**Alternative:** Rearrange metrics columns separately - rejected because it breaks logical grouping.

### Decision 3: Status-Specific Column Headers
**Choice:** Adjust the "Kas" column header to reflect content (keep "Kas" as parent concept), and use secondary text or color coding to distinguish Ekspektasi vs Akhir.

**Rationale:**
- Column header stays consistent, cell content adapts
- Reduces cognitive load - users know column 8 is always cash-related data
- Matches the domain model where expected vs actual are stages of the same concept

## Risks / Trade-offs

**[Risk] Empty cells for non-applicable statuses**
- Mitigation: Show `-` or a muted placeholder to indicate "not applicable" rather than blank space. This is clearer and maintains table structure visibility.

**[Risk] Column width changes if values vary drastically**
- Mitigation: Use CSS table-layout: fixed or explicit column widths to prevent shifts. Constrain "Aksi" buttons to a fixed width.

**[Risk] Users might expect different columns per status**
- Mitigation: Maintain clarity with good header documentation and status badges. The UI is now more predictable.

## Migration Plan

1. Update the Blade template to remove all `@if($status === ...)` conditions from the `<thead>`
2. Add two additional `<td>` cells in the `<tbody>` loop (for Aktivitas Terakhir always present)
3. Use `@if` only on cell *content*, not cell *structure* - conditionally show values, not column counts
4. Add CSS to ensure fixed column widths and prevent reflow
5. Test with filters: view all statuses (null), OPEN, CLOSED to verify alignment
6. No database migration needed
7. No changes to controller logic

## Open Questions

- Should the "Aktivitas Terakhir" column header be relabeled for closed sessions, or just show `-`?
- Do we want visual distinction (subtle background color) for cells with placeholder values?
- Should this follow any existing table normalization patterns in the application?
