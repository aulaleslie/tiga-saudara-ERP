## Context

The Reports module already has a landing page, Livewire report screens, service-backed financial calculations, Maatwebsite Excel exports, and focused feature tests. `Laporan Laba Rugi`, `Neraca`, and `Buku Besar` are active under Reports > Sekilas bisnis, while `Arus kas` is currently a placeholder card.

The existing financial-report pattern is explicitly operational. `Neraca` and `Buku Besar` derive report rows from sales, purchases, returns, payments, expenses, inventory, and current setting/currency context rather than complete chart-of-account journal postings. The cash flow report should follow that same stance: useful and testable from current operational data, while clearly avoiding claims that it is a real bank ledger or complete accounting cash-flow statement.

The provided sample files under `report-sample/arus-kas` show a direct-method `Arus Kas` report with operating, investing, financing, and summary rows. The sample UI also includes richer features such as PDF export, template selection, indirect method, comparison periods, tag grouping, and a `Tampilkan akun` toggle. Those features are valuable context but should be staged carefully because the ERP does not yet have enough accounting/bank-ledger data to support all of them accurately.

## Goals / Non-Goals

**Goals:**

- Add an active `Arus kas` report under Reports > Sekilas bisnis using `reports.access`.
- Calculate a direct-method operational cash flow for a selected date range and active `setting_id`.
- Present sections and row labels aligned with the sample: operating, investing, financing, net cash movement, bank revaluation placeholder, opening cash, and ending cash.
- Derive opening cash from supported operational cash movement before the start date and ending cash from opening cash plus period movement.
- Reuse existing Laravel, Livewire, Reports module, service/value-object, permission, formatting, and export patterns.
- Provide XLSX and CSV exports from the same report value object used by the screen.
- Include source notes explaining that the report is operational and not backed by complete journal, COA, bank ledger, opening capital, or bank revaluation data.

**Non-Goals:**

- Do not implement full accounting journal posting, chart-of-account cash-flow mapping, or bank ledger balances.
- Do not add database schema, opening cash configuration, loan/capital records, or bank revaluation records in this first version.
- Do not implement PDF export in this first version.
- Do not implement custom report templates, `Default Direct`/`Default Indirect` switching, or user-created templates.
- Do not implement comparison-period columns.
- Do not implement account drill-down or a full `Tampilkan akun` account tree in the first version.
- Do not rewrite existing transaction lifecycle behavior or payment storage semantics.

## Decisions

### Decision: Use an operational cash-event normalization layer

Create an `OperationalCashFlowReportService` that normalizes supported operational records into signed cash events, then aggregates them into cash-flow sections and summary rows.

The first event sources should be:

- active sale payments as operating cash inflow
- active purchase payments as operating cash outflow
- completed sale return payments as operating cash outflow
- completed purchase return payments as operating cash inflow
- approved, non-archived expenses as operating cash outflow

Rationale: a cash-event layer keeps calculation rules testable, gives the screen and exports one shared source, and mirrors the existing `Buku Besar` movement-event approach without pretending to be double-entry accounting.

Alternative considered: reuse `OperationalGeneralLedgerReportService` cash bucket output directly. Rejected because cash flow needs business cash-flow labels and summary rows, not debit/credit ledger rows.

### Decision: Keep investing and financing sections visible but zero-valued until sources exist

The report should show investing and financing sections with sample-aligned rows such as `Perolehan/Penjualan aset`, `Aktivitas investasi lainnya`, `Pembayaran/Penerimaan pinjaman`, and `Ekuitas/Modal`, but their amounts should remain zero in the first implementation unless a reliable existing source is identified during implementation.

Rationale: the sample structure is preserved, but the ERP currently lacks durable fixed-asset sale/purchase, loan movement, owner-capital, or bank-transfer sources that can be classified safely.

Alternative considered: infer investing/financing from expense categories, payment methods, or names. Rejected because string/category inference would create misleading financial output.

### Decision: Calculate opening and ending cash from supported historical cash events

For a selected date range:

```text
opening_cash = sum(supported cash events before start_date)
period_net_cash = sum(supported cash events between start_date and end_date)
bank_revaluation = 0
ending_cash = opening_cash + period_net_cash + bank_revaluation
```

Rationale: this makes the report internally consistent and testable without introducing a new opening-balance model.

Alternative considered: use current `Neraca` cash/bank as ending cash and derive opening from the difference. Rejected because it hides the same data limitations and makes date-range behavior harder to reason about.

### Decision: Make the operational limitation visible in UI and export

The report should include a source note explaining that cash is derived from supported operational payments and approved expenses, not complete journals, COA posting, bank ledger balances, opening cash/capital, bank transfers, or real bank revaluation.

Rationale: existing `Neraca` and `Buku Besar` reports already use this transparency pattern. It reduces the risk that users treat operational approximations as audited accounting statements.

Alternative considered: omit the note to match the clean sample UI. Rejected because this ERP's data source is materially different from the sample's implied accounting engine.

### Decision: Implement XLSX and CSV export parity, defer PDF

The export class should use the same report value object as the Livewire component. XLSX should use styled title/header rows similar to existing operational exports and the sample workbook. CSV should use a flat row set like the sample:

```text
Tipe Aktivitas,Nama Label,<period label>
Arus kas dari Aktivitas Operasional,Penerimaan dari pelanggan,<amount>
...
Kenaikan (penurunan) kas,,<amount>
Saldo kas akhir,,<amount>
```

Rationale: the sample includes XLSX and CSV, and existing newer list reports already enforce export parity. PDF is explicitly shown in the sample UI but is broader and currently absent from nearby operational financial reports.

Alternative considered: ship XLSX only to match `Neraca` and `Buku Besar`. Rejected because the user explicitly asked to check XLSX and CSV, and the sample includes both.

### Decision: Start with simple date controls and period presets

The first UI should include start date, end date, period preset, apply/reset controls, and an export dropdown. It should follow the existing Bootstrap/Livewire report UI patterns and may use a compact top filter row rather than recreating the full Mekari drawer.

Rationale: existing report screens use Bootstrap/CoreUI conventions. A faithful functional approximation is safer than introducing a large new drawer system for one report.

Alternative considered: implement the full sample drawer including comparison count, tag grouping, and show-account toggle. Rejected for first version because several controls need data semantics that are not yet reliable.

### Decision: Treat tag filtering as optional first-version scope

If implementation can safely inherit tags from source documents already using Spatie tags, the report may include tag filtering with the established `Salah satu`/`Mencakup semua` semantics. If payment rows cannot be consistently traced to tagged source documents, the first implementation should defer tag filtering rather than show a misleading filter.

Rationale: sale and purchase reports already contain tag filtering patterns, but cash-flow rows come from payments and return payments, not only tagged documents.

Alternative considered: filter only sale and purchase payment rows by parent tags and leave expense/return rows unaffected. Rejected because mixed filter semantics would confuse users.

## Risks / Trade-offs

- [Risk] Opening and ending cash may not match real cash/bank balances because opening cash, bank transfers, owner capital, loans, and manual bank movements are not fully represented. -> Mitigation: label the report as operational and calculate from a clearly documented set of supported cash events.
- [Risk] Users may assume investing and financing zero rows mean no such activity occurred. -> Mitigation: include a source note and keep future work explicit for asset, loan, capital, and bank-ledger sources.
- [Risk] Legacy return/payment rows may have different money unit conventions. -> Mitigation: reuse the normalization logic already hardened in `Neraca` and `Buku Besar` tests, and add regression tests for legacy/livewire purchase return payments.
- [Risk] Export output can drift from the screen. -> Mitigation: one service/value-object result feeds screen, XLSX, and CSV; tests assert representative parity.
- [Risk] Date filtering can become expensive if it scans all historical payment rows for opening cash. -> Mitigation: keep queries scoped by `setting_id`, status, date, and source table indexes; use focused service tests and avoid loading unnecessary relations.
- [Risk] Tag filtering is attractive but easy to misstate. -> Mitigation: defer it unless it can be applied uniformly across every included cash event type.

## Migration Plan

No database migration is expected. Deployment is additive:

- Add route, controller method, Blade wrapper, Livewire component, report service/value objects, export class, and tests.
- Activate the Reports landing `Arus kas` card by assigning it a route and removing placeholder behavior.
- Reuse `reports.access` for route and component access control.

Rollback is straightforward: remove or disable the route/component/service/export and restore the `Arus kas` landing card to placeholder status.

## Open Questions

- Should first-version tag filtering be included only if every included cash event can be filtered consistently through its source document?
- Should POS session cash movements, opening floats, pickups, and finalization records be included in v1 operating cash, or handled in a later POS-specific cash report reconciliation pass?
- Should the UI display disabled `PDF` and template controls to mirror the sample, or omit unavailable controls entirely until implemented?
