## Context

The Reports landing page already includes a Pembelian card named `Penyelesaian pesanan pembelian`, but it is marked as a placeholder. The closest existing production pattern is the sales-side completion report:

- `App\Livewire\Reports\SalesOrderCompletionReport`
- `App\Services\Reports\SalesOrderCompletionReportQueryService`
- `App\Services\Reports\SalesOrderCompletionReportFilterData`
- `App\Services\Reports\SalesOrderCompletionReportValidator`
- `App\Services\Reports\SalesOrderCompletionReportSnapshotService`
- `App\Exports\SalesOrderCompletionReportExport`
- `Modules\Reports\Http\Controllers\SalesOrderCompletionReportController`
- `resources/views/livewire/reports/sales-order-completion-report.blade.php`

The purchase side already has reusable calculation references:

- Purchase header/payment semantics in `PurchaseReportQueryService`
- Approved receiving-note valuation semantics in `PurchaseDeliveryReportQueryService`
- Purchase report permission and landing navigation patterns in `Modules/Reports`

The report sample under `report-sample/penyelesaian-pemesanan-pembelian` defines the target UX and export shape: `Penyelesaian Pemesanan Pembelian`, `purchase_order_completion`, IDR currency label, summary columns, date/supplier/tag/source filters, XLSX metadata rows, and CSV table-only output.

## Goals / Non-Goals

**Goals:**

- Implement the purchase-side order completion report as an actionable report reachable from the Reports landing page.
- Keep behavior read-only against purchase, receiving, and payment records.
- Reuse the existing sales completion report architecture where practical so behavior is familiar and testable.
- Calculate purchase completion rows from existing purchase headers, active purchase payments, and approved receiving notes.
- Match the sample column labels and export shape while keeping ERP-specific Laravel conventions.
- Protect exports with an applied-filter snapshot so exported results match the last filtered report state.

**Non-Goals:**

- No database migrations or persistent reporting tables.
- No PDF export in first scope, even though the external sample UI displays a PDF option.
- No implementation of the sample's `Order Completion Detail` template in first scope; this change covers the summary report.
- No changes to purchase approval, receiving approval, payment invalidation, purchase return, stock, serial, or import workflows.
- No global cross-setting version unless a future proposal explicitly adds it.

## Decisions

### Decision: Introduce a purchase-specific completion report rather than generalizing the sales report

Create purchase-specific classes named around `PurchaseOrderCompletionReport` instead of abstracting sales and purchase completion into a shared engine.

Rationale:

- Sales and purchase lifecycles use different source tables, statuses, receiving/dispatch concepts, and payment storage conventions.
- The existing sales report can remain stable while the purchase report borrows its UX and snapshot/export architecture.
- A shared abstraction would add risk before the purchase behavior is proven.

Alternative considered: extract a generic `OrderCompletionReport` base service. Rejected for first scope because it would touch an already-working sales report and increase regression surface.

### Decision: Query one row per purchase header

The report uses `purchases` as the row grain and joins/subqueries derived amounts onto each purchase.

Rationale:

- The sample is a summary report with one row per order number.
- Purchase details and receiving details are needed only for valuation aggregates, not for row expansion.
- This matches the existing sales completion report's one-row-per-sale behavior.

### Decision: Use purchase date for report inclusion, but approved receiving notes for delivery amount

The selected period filters purchases by `purchases.date`. `Jumlah Pengiriman` is a derived amount from approved receiving notes related to those purchases.

Rationale:

- The sample labels the primary date as `Tanggal Pemesanan`, so inclusion should follow the order date.
- Existing `PurchaseDeliveryReport` is receiving-date based, but this completion report is order-completion based.
- This allows users to see whether purchases ordered in the period have been received, invoiced, and paid.

Trade-off:

- A receiving note inside the period for an older purchase will not appear unless the purchase date is also in the selected period. This is intentional for order-completion semantics and differs from the purchase delivery report.

### Decision: Calculate receiving amount proportionally from approved receiving quantities

`Jumlah Pengiriman` is calculated from approved `received_notes` / `received_note_details`, using:

`quantity_received * (purchase_details.sub_total / purchase_details.quantity)`

Rationale:

- This follows the existing purchase delivery report's commercial valuation pattern.
- It supports partial receiving without requiring new persisted amounts.
- Pending and rejected receiving notes do not represent completed receiving progress and are excluded.

Alternative considered: use received quantity only. Rejected because the sample column is monetary and the UI labels currency `(dalam IDR)`.

### Decision: Derive payment from active purchase payments with existing fallback behavior

`Jumlah Pembayaran` uses active `purchase_payments` where possible. If no purchase payment rows exist, it may fall back to persisted purchase header payment fields using the same compatibility approach as existing purchase reports.

Rationale:

- Active payment rows are the current source of truth after payment invalidation work.
- Fallback preserves older data that may predate explicit payment rows.

### Decision: Treat invoice amount as the purchase total after order approval

`Jumlah Faktur` should be zero for `DRAFTED` and `WAITING_APPROVAL` source rows, and otherwise use `purchases.total_amount`.

Rationale:

- This mirrors the sales completion report's treatment of draft/waiting records.
- It prevents unapproved quotation/order rows from appearing as invoiced.

### Decision: Use purchase status plus payment/invoice amounts for completion status

`Status Pemesanan` is a derived report label:

- Draft or waiting approval: `Belum Dibayar`
- No active/effective payment: `Belum Dibayar`
- Effective paid amount greater than or equal to invoice amount: `Selesai`
- Otherwise: `Terbayar Sebagian`

Rationale:

- This matches the sales completion report and the sample's `Selesai` label.
- It keeps the completion report focused on financial completion, while receiving amount remains a separate progress column.

Open implementation note:

- If stakeholders later need receiving-aware labels such as `Diterima Sebagian`, that should be a follow-up because it changes the report's business meaning.

### Decision: Scope source-stage filter to purchase lifecycle status groups

`Mulai dari = Penawaran` includes draft-like purchase rows. `Mulai dari = Pemesanan` includes active purchase order rows after submission/approval and receiving/return lifecycle progress.

Suggested status groups:

- `Penawaran`: `DRAFTED`
- `Pemesanan`: `WAITING_APPROVAL`, `APPROVED`, `RECEIVED PARTIALLY`, `RECEIVED`, `RETURNED PARTIALLY`, `RETURNED`

Rationale:

- This mirrors sales completion report behavior while using purchase canonical statuses.
- `REJECTED` is excluded from first scope because it is not a completion progress row.

### Decision: Follow existing report export conventions, with explicit numeric rounding

XLSX includes metadata rows:

- Company name
- `purchase_order_completion`
- selected date range
- `(dalam IDR)`

CSV contains only headings and data rows. Numeric CSV values should be rounded/formatted to two decimal places to avoid floating artifacts like `20055000.000002`.

Rationale:

- Matches the report sample and existing Maatwebsite Excel usage.
- Explicit CSV formatting avoids exposing binary/decimal drift.

### Decision: Replace the landing placeholder with a route-backed card

Add a `reports.purchase-order-completion.index` route, controller, module view, and Livewire mount. Update `ReportsController` card metadata so `Penyelesaian pesanan pembelian` links to the new route and is no longer placeholder-treated.

Rationale:

- The card already exists in the correct category and permission scope.
- This is the minimal navigation change users need.

## Risks / Trade-offs

- [Risk] Monetary values can drift if calculations use unrounded floating values → Mitigation: compute with database decimals where possible and round/format only at display/export boundaries.
- [Risk] Payment values may be inconsistent between legacy header fields and active payment rows → Mitigation: reuse existing purchase report effective-payment fallback semantics and cover both cases in tests.
- [Risk] Purchase receiving valuation can overcount malformed receiving details with zero or missing purchase quantities → Mitigation: guard division by zero and treat invalid unit valuation as zero.
- [Risk] Users may expect the date range to filter receiving dates instead of purchase dates → Mitigation: label and tests anchor inclusion to `Tanggal Pemesanan`; receiving-date reporting remains covered by `Pengiriman pembelian`.
- [Risk] Updating the Reports landing card can break permission visibility → Mitigation: add route/card tests for authorized and unauthorized users.
- [Risk] Duplicating the sales report architecture creates parallel code → Mitigation: keep duplication intentional and local; consider abstraction only after both reports stabilize.
