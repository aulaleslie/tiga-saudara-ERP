## Context

The purchase index page (`Modules/Purchase/Resources/views/index.blade.php`) currently renders a jQuery-based DataTable via a Livewire wrapper (`livewire:purchase.purchase-table`). There is no summary layer — users must read the table to assess financial health.

The `purchases` table has `status`, `payment_status`, `due_date`, `due_amount`, and `paid_amount` fields sufficient to compute all three cards server-side. The `purchase_payments` table exists but is currently empty in production; the fallback is `purchases.date`.

## Goals / Non-Goals

**Goals:**
- Render three summary cards above the DataTable on the purchase index page
- Compute counts and totals server-side in PHP on component mount (no polling)
- Allow card clicks to pre-filter the DataTable to the corresponding subset
- Gracefully fall back from `purchase_payments.date` to `purchases.date` for Pelunasan

**Non-Goals:**
- Real-time / auto-refreshing counts (no `wire:poll`)
- Filtering by supplier, date range, or any dimension beyond the three card subsets
- Modifying the DataTable component itself or its server-side query logic

## Decisions

### D1: Livewire component vs. controller-passed data

**Decision:** New Livewire component `PurchaseSummaryCards`.

**Rationale:** The existing index view already uses Livewire (`purchase-table`). A Livewire component keeps summary logic encapsulated and allows Alpine.js/`$dispatch` to coordinate card-click → DataTable filter without touching the controller. Passing data from the controller would require modifying `PurchaseController::index()` and coupling it to the view.

### D2: DataTable filter integration

**Decision:** On card click, dispatch a browser event (`purchase-filter`) via Alpine.js `$dispatch`. The purchase-table Livewire component listens with `#[On('purchase-filter')]` and applies the filter to its query.

**Rationale:** The DataTable is rendered by `livewire:purchase.purchase-table`. Livewire's `#[On]` attribute is the idiomatic cross-component communication pattern already used in this codebase. This avoids jQuery DataTable API calls from outside the component.

**Alternative considered:** Direct jQuery DataTable `fnFilter` call from the card blade — rejected because it couples the card view to jQuery internals and breaks if the DataTable is ever replaced.

### D3: Pelunasan date source

**Decision:** Check `purchase_payments` table for any ACTIVE rows with `date >= 30 days ago`. If found, use that table for count and sum. Otherwise fall back to `purchases.date >= 30 days ago` with `payment_status = PAID`.

**Rationale:** `purchase_payments` is the authoritative source once the payment recording workflow is in use. The fallback ensures the card is useful before that workflow is adopted.

### D4: Card styling

**Decision:** Bootstrap 4 card with colored left-border (`border-left` utility) matching the existing app pattern — no new CSS classes. Colors: blue for belum dibayar, red for telat bayar, green for pelunasan.

## Risks / Trade-offs

- **Stale counts on DataTable reload** → Cards are mounted once; counts don't auto-refresh when the DataTable data changes. Acceptable for a summary widget; users can refresh the page.
- **Pelunasan fallback inaccuracy** → Using `purchases.date` (invoice date) as a proxy for payment date is semantically incorrect. Count will diverge from reality once `purchase_payments` is populated. Mitigation: the fallback is removed automatically once `purchase_payments` has data.
- **purchase-table Livewire component may not exist yet** → The index view references `livewire:purchase.purchase-table` but no corresponding PHP class was found. If this is a DataTables-native component (not Livewire), the filter integration via `#[On]` won't work. In that case, the card click will use `window.LaravelDataTables['purchases-table'].search(filterValue).draw()` instead.

## Open Questions

- Does `livewire:purchase.purchase-table` correspond to a real Livewire class, or is it rendered via the DataTables package's Livewire integration? This determines which filter mechanism to use (verified during implementation).
