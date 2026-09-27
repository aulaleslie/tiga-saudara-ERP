## Context

The Reports module already has a landing page, Livewire report screens, service-backed financial calculations, Maatwebsite Excel exports, and focused feature tests. `Neraca`, `Buku Besar`, and `Arus Kas` follow an explicit operational-reporting stance: they derive values from sales, purchases, payments, returns, expenses, inventory, and setting context rather than complete chart-of-account journal postings.

`Neraca saldo` is currently visible on the Reports landing page as a placeholder card. The sample files under `report-sample/neraca-saldo` show a trial-balance-style report with date range controls, category group rows, account rows, opening debit/credit, period debit/credit, ending debit/credit, totals, XLSX export, and CSV export. The ERP has `chart_of_accounts`, `journals`, and `journal_items`, but operational modules do not yet post complete accounting journals. A true COA trial balance would therefore be misleading in this iteration.

## Goals / Non-Goals

**Goals:**

- Add an active `Neraca saldo` report under Reports > Sekilas bisnis using `reports.access`.
- Calculate a trial-balance-style operational report for a selected date range and active `setting_id`.
- Reuse or extract the operational movement normalization already used by `Buku Besar` so debit/credit behavior stays consistent across reports.
- Show grouped rows with opening debit/credit, period debit/credit, ending debit/credit, and total columns aligned with the sample structure.
- Provide XLSX and CSV exports from the same report value object used by the screen.
- Include a source note explaining that this is calculated from operational transactions and not complete accounting journals or chart-of-account posting.

**Non-Goals:**

- Do not implement accounting journal posting, automatic COA posting, or journal backfill.
- Do not calculate the report from `journal_items` in this first version.
- Do not claim true chart-of-account balances or audited trial-balance accuracy.
- Do not add database schema, opening balance configuration, account mapping tables, or new permissions.
- Do not implement PDF export, custom report templates, column-density controls, or full Mekari-style filter drawers in the first version.
- Do not rewrite existing operational transaction lifecycle behavior.

## Decisions

### Decision: Build an operational trial balance, not a COA trial balance

The report will use the user-facing title `Neraca saldo`, but it will calculate rows from supported operational movement sources. It will include a visible note explaining that it does not yet use complete accounting journals or chart-of-account posting.

Rationale: the app does not yet have an accounting system that posts operational documents into complete double-entry journal items. Using `journal_items` would understate or misrepresent operational activity.

Alternative considered: query `journal_items` grouped by `chart_of_accounts`. Rejected because manually entered journal data is not the operational source of truth and would not match sales, purchases, POS, returns, or payments reliably.

### Decision: Reuse a normalized operational movement layer

The implementation should extract reusable movement-event normalization from `OperationalGeneralLedgerReportService` or introduce a shared internal service consumed by both Buku Besar and Neraca saldo. Events should carry bucket/category, row label, normal balance direction, date, debit amount, credit amount, source type, reference, and setting scope.

Rationale: Buku Besar already encodes the hard parts: eligible sales, payments, purchases, returns, expenses, legacy/livewire purchase return scaling, and bucket debit/credit direction. Duplicating those rules would create report drift.

Alternative considered: re-query each source table directly in a new trial balance service. Rejected because it risks inconsistent eligibility, amount scaling, and return semantics.

### Decision: Present synthetic operational rows with optional synthetic codes

The first implementation should present report rows as operational buckets or stable synthetic account-like rows, not real COA accounts. If the UI/export needs a `Nomor Akun` column to follow the sample shape, codes should be clearly synthetic and stable, such as `OP-100` for `Kas & Bank dari Transaksi`, rather than values from `chart_of_accounts`.

Rationale: the sample includes account numbers, but true COA numbers imply ledger precision that the system cannot support yet. Stable synthetic codes preserve table readability without pretending to be accounting data.

Alternative considered: leave the account-number column blank. This is acceptable if the screen reads better, but CSV/XLSX parity with the sample is stronger when the column exists. The implementation should prefer clarity over visual mimicry.

### Decision: Calculate debit and credit columns from normal balance direction

For each row, the service should calculate:

```text
opening_net = net movement before start_date
period_debit = debit movement between start_date and end_date
period_credit = credit movement between start_date and end_date
ending_net = opening_net + period net movement
```

Then split `opening_net` and `ending_net` into debit or credit columns based on the row's normal balance direction.

Rationale: the sample's trial balance format requires separate debit and credit balance columns. The existing Buku Besar bucket rules already define whether debit or credit increases a bucket.

Alternative considered: show signed single balance columns. Rejected because it no longer resembles Neraca saldo and would not match the sample export shape.

### Decision: Keep category grouping broad and operational

The first version should group rows into broad categories such as `Assets`, `Liability`, `Equity`, `Income`, and `Expense` only where supported by operational data. Suggested row mapping:

- Assets: `Kas & Bank dari Transaksi`, `Piutang Usaha`, and possibly `Persediaan Barang` only if the source is consistent with existing operational reports.
- Liability: `Hutang Usaha`.
- Income: `Pendapatan Operasional` and revenue-like return/adjustment rows where applicable.
- Expense: `Pembelian / Biaya Operasional` and expense-like return/adjustment rows where applicable.
- Equity: optional zero or derived balancing row only if needed for a complete report shape; it must be clearly operational if included.

Rationale: the app can support management-facing operational categories, not detailed COA classes. Grouping must stay honest about available sources.

Alternative considered: create many synthetic rows matching the sample account names. Rejected because names like `PPN Masukan`, `PPN Keluaran`, or detailed expense accounts need data that is not yet consistently posted.

### Decision: Use sample-aligned exports with explicit operational notes

XLSX should use a two-level header similar to the sample: account/category columns, `Saldo Awal`, `Pergerakan`, and `Saldo Akhir` split into Debit/Credit columns, category separator rows, and totals. CSV should use a flat row structure with category, account code, account name, and numeric amount columns.

Rationale: the provided sample includes both XLSX and CSV. Export parity is important because users often reconcile reports outside the app.

Alternative considered: ship XLSX only like the existing Neraca and Buku Besar reports. Rejected because the sample includes CSV and the newer Arus Kas scope already established CSV parity for sample-backed reports.

## Risks / Trade-offs

- [Risk] Users may interpret the report as a true accounting trial balance. -> Mitigation: include an operational source note in the screen and exports, avoid real COA drill-down, and avoid claiming accounting-journal accuracy.
- [Risk] Synthetic row codes can be mistaken for real COA account numbers. -> Mitigation: use clearly operational labels, document the source note, and consider `OP-*` codes or blank codes rather than COA-like numeric codes.
- [Risk] Duplicating movement rules can cause drift from Buku Besar. -> Mitigation: extract/reuse the movement normalization logic and add tests that compare representative trial balance totals to the underlying ledger bucket output.
- [Risk] Opening balances require scanning historical movement before the start date. -> Mitigation: keep queries scoped by `setting_id`, status, date, and source table indexes; reuse existing report query patterns.
- [Risk] Inventory and equity are difficult without a real ledger. -> Mitigation: include only supported operational rows in v1, and keep unsupported accounting categories omitted or explicitly zero only when the report shape requires it.
- [Risk] Legacy return payment amount scaling can distort totals. -> Mitigation: reuse the existing normalization logic from operational balance sheet/general ledger and add focused regression tests.

## Migration Plan

Deployment is additive:

- Add the route, controller wrapper, Livewire component, Blade view, service/value objects, export classes, and focused tests.
- Activate the `Neraca saldo` landing card by assigning a real route and removing placeholder behavior.
- Reuse `reports.access` for route and component authorization.

Rollback is straightforward: remove or disable the route/component/service/export files and restore the landing card to placeholder state. No database rollback is expected.

## Open Questions

- Should the first screen show a `Nomor Akun` column with stable synthetic `OP-*` codes, or omit/blank the column to avoid account-number confusion?
- Should `Persediaan Barang` appear in the first operational trial balance if the only available valuation is the current stock valuation rather than period-accurate stock movement?
- Should CSV export include the source note as metadata rows, or stay strictly flat like the sample CSV?
