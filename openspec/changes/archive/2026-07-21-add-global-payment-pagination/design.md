## Context

The Global Purchase Payment view (`Modules/Purchase/Resources/views/payments/global-create.blade.php`) handles paying multiple unpaid invoices for a single supplier. Currently, it loads all unpaid invoices onto a single HTML page. For suppliers with a large volume of unpaid invoices, the UI becomes unmanageable. Furthermore, identifying invoices is difficult because the external supplier purchase number is not displayed.

## Goals / Non-Goals

**Goals:**
- Display the `supplier_purchase_number` alongside the internal purchase number in the invoice list.
- Implement client-side pagination on the invoice list using DataTables to handle tens or hundreds of invoices gracefully.
- Ensure that form inputs across all pages of the DataTable are successfully submitted when the user clicks the save button.
- Provide a standard ERP page length configuration of `[10, 25, 50, 100, "All"]`.

**Non-Goals:**
- Refactor the controller or view to Livewire (as the volume typically stays in the tens, client-side pagination is sufficient and minimizes refactoring risk).
- Server-side pagination (the full dataset will still be fetched and loaded into HTML).

## Decisions

- **Client-Side DataTables vs Server-Side Livewire**: We've chosen client-side DataTables over a full Livewire refactor because it's significantly faster to implement, integrates perfectly with the existing `jquery-mask-money.js` scripts, and performs adequately for the expected data volume (tens of invoices per supplier).
- **Handling Form Submission with DataTables**: Because DataTables removes hidden rows from the DOM, a standard jQuery `$('.allocation-input').each(...)` will miss allocations on inactive pages. We will use the DataTables API (`table.$('.allocation-input')`) to serialize all inputs (both visible and hidden) before the form is submitted to ensure no data is lost.

## Risks / Trade-offs

- **Risk: Performance degradation for massive suppliers.** → **Mitigation:** If a supplier ever has thousands of unpaid invoices, rendering the initial HTML table will be slow. This is accepted as an edge case. If it becomes a frequent issue, we'll need to reconsider server-side pagination.
- **Risk: Input formatting lost on hidden pages.** → **Mitigation:** Ensure that `jquery-mask-money` initialization and calculation logic runs correctly by utilizing DataTables event callbacks (like `draw.dt`) if necessary, or simply ensuring the hidden inputs (`#allocation_hidden_{id}`) remain accurately synced.
