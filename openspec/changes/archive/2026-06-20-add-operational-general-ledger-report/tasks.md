## 1. Report Entry Points

- [x] 1.1 Add a Buku Besar report route under the existing authenticated Reports routes and gate it with `reports.access`.
- [x] 1.2 Add a Reports module controller/view entry that renders the Buku Besar Livewire component.
- [x] 1.3 Convert the Reports landing Buku Besar card from placeholder to active route link while preserving `reports.access` gating.
- [x] 1.4 Update landing tests to assert Buku Besar appears as an actionable Sekilas bisnis card for permitted users and remains hidden/forbidden for unauthorized users.

## 2. Report Data Model

- [x] 2.1 Create report value objects for the Buku Besar report, bucket groups, movement rows, balances, filters, and source note.
- [x] 2.2 Define supported operational bucket keys and labels without COA account numbers.
- [x] 2.3 Implement date range and bucket filter normalization with default start/end date set to today.
- [x] 2.4 Add helper methods for bucket-direction debit, credit, net movement, and running balance calculations.

## 3. Operational Movement Service

- [x] 3.1 Implement a Buku Besar report service that returns one render/export-ready report object for a setting, date range, and optional bucket filters.
- [x] 3.2 Normalize eligible sales into operational revenue and receivable movement events using completed sale statuses.
- [x] 3.3 Normalize active sale payments into cash/bank inflow and receivable reduction movement events.
- [x] 3.4 Normalize completed sale returns and sale return payments into supported reversal and cash/bank outflow movement events.
- [x] 3.5 Normalize eligible purchases into purchase/cost and payable movement events using completed purchase statuses.
- [x] 3.6 Normalize active purchase payments into cash/bank outflow and payable reduction movement events.
- [x] 3.7 Normalize completed purchase returns and purchase return payments into supported reversal and cash/bank inflow movement events, including legacy/livewire amount scaling rules where needed.
- [x] 3.8 Normalize approved, non-archived expenses into expense and cash/bank outflow movement events.
- [x] 3.9 Scope all operational movement queries to the active `setting_id` and exclude draft, rejected, incomplete, archived, or invalidated records according to existing report semantics.
- [x] 3.10 Calculate beginning balance from movement before the start date, period movement inside the selected date range, running balances in date/reference order, and ending balances per bucket.
- [x] 3.11 Omit detailed inventory movement rows unless implementation identifies a reliable historical movement source; otherwise keep inventory out of the first-version movement ledger.

## 4. Livewire UI

- [x] 4.1 Create the Buku Besar Livewire component with `reports.access` authorization, date range fields, bucket filter state, filter action, reset action, and export action.
- [x] 4.2 Build the report Blade view with title `Buku Besar`, currency label, date range controls, filter actions, XLSX export button, and operational source note.
- [x] 4.3 Render grouped bucket sections with columns for `Nama Akun / Tanggal`, `Transaksi`, `No.`, `Deskripsi`, `Debit`, `Kredit`, `Saldo`, and `Tag` where data is available.
- [x] 4.4 Show beginning balance, movement rows, period debit/credit totals, and ending balance for each visible bucket.
- [x] 4.5 Show buckets that have period movement or non-zero beginning/ending balance, and hide buckets with no movement and zero balances.
- [x] 4.6 Render a clear empty state when the selected filters have no eligible movement or non-zero balances.
- [x] 4.7 Ensure the UI does not render COA account numbers, account drill-down links, account depth controls, or journal-backed wording.

## 5. XLSX Export

- [x] 5.1 Create `OperationalGeneralLedgerReportExport` class implementing `FromView` and `ShouldAutoSize`.
- [x] 5.2 Reuse the `OperationalGeneralLedgerReportService` to fetch the report object using the exported filter payload.
- [x] 5.3 Create `resources/views/exports/operational-general-ledger-report.blade.php` applying the same structural and exclusion constraints as the UI, bucket group headers, movement rows, debit/credit/balance columns, and ending balance rows.
- [x] 5.4 Include the operational source note in the export.
- [x] 5.5 Use a stable filename that includes `buku-besar` and the selected date range.

## 6. Tests

- [x] 6.2 Add service tests for debit/credit direction semantics for cash, receivables, payables, revenue, and cost/expense buckets.
- [x] 6.3 Add service tests for beginning balance, running balance, ending balance, quiet non-zero bucket visibility, and zero empty bucket omission.
- [x] 6.4 Add service tests for date range filtering, bucket filtering, and active `setting_id` scoping.
- [x] 6.5 Add Livewire tests for default today period, applying filters, rendering the source note, rendering the empty state, and forbidding unauthorized access.
- [x] 6.6 Add export parity tests proving the XLSX export uses the same filters, bucket rows, balances, and source note as the screen report.
- [x] 6.7 Add regression coverage for legacy/livewire purchase return amount scaling if purchase return payment rows are included in the service.

## 7. Verification

- [x] 7.1 Run focused Buku Besar service, Livewire, export, and Reports landing tests.
- [x] 7.2 Run existing operational balance sheet and profit/loss focused tests to catch shared-report regression.
- [x] 7.3 Run `php artisan test` with focused filters or `composer test:fresh-sqlite` when the touched test surface justifies a wider pass.
- [x] 7.4 Manually review the report against `report-sample/buku-besar/ui.txt` and `report-sample/buku-besar/ui-filter.txt` for layout vocabulary while confirming COA/account controls are not copied.
