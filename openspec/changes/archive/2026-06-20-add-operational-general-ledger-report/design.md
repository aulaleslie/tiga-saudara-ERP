## Context

The Reports module already has a landing page, Livewire report screens, service-backed report calculations, XLSX exports, and focused feature tests. The Sekilas bisnis tab currently exposes Laporan Laba Rugi and Neraca as active reports, while Buku Besar remains a placeholder card.

The application has chart-of-account and journal-related tables, but operational modules do not yet post complete double-entry accounting journals. Recent financial report work established the pattern that management-facing reports can use operational transactions directly when the report clearly states that it is not backed by accounting journals. Buku Besar should follow that pattern while preserving the user-facing report name.

## Goals / Non-Goals

**Goals:**

- Add a usable report titled `Buku Besar`.
- Present a Jurnal-style ledger table with date range filters, grouped rows, debit, credit, running balance, and ending balance.
- Calculate rows from operational transactions scoped to the active `setting_id`.
- Convert the Reports landing Buku Besar card from placeholder to active link for `reports.access` users.
- Use a shared report service/value-object shape for screen and XLSX export parity.
- Include an explicit note that values are derived from operational transactions and do not yet use COA/journal posting.
- Support optional filtering by operational bucket/category instead of COA account.

**Non-Goals:**

- Do not implement general ledger posting, double-entry accounting, or journal backfill.
- Do not depend on `journal_items` or chart-of-account balances.
- Do not show COA account numbers, account depth filtering, account links, or COA hierarchy.
- Do not implement detailed historical inventory movement rows in the first version.
- Do not add PDF or CSV export in the first version.
- Do not add new permissions or database schema.
- Do not rewrite existing operational transaction lifecycle behavior.

## Decisions

### D1: Keep the title Buku Besar, but make the source explicit

The page, landing card, export title, and route-facing label will use `Buku Besar`. The body and export will include a source note explaining that the report is calculated from operational transactions and does not yet use accounting journals or COA posting.

Rationale: users expect the familiar report name, but the system must avoid implying accounting precision it does not have.

Alternative considered: rename the UI to `Buku Besar Operasional`. Rejected because the user explicitly wants to keep the Buku Besar name.

### D2: Group by operational buckets instead of COA accounts

The first version will group rows by derived buckets such as:

- `Kas & Bank dari Transaksi`
- `Piutang Usaha`
- `Hutang Usaha`
- `Pendapatan Operasional`
- `Pembelian / Biaya Operasional`
- `Retur / Koreksi`

The UI filter should replace `Mencakup akun` semantics with bucket/category filtering. The report can still place the bucket label in the `Nama Akun / Tanggal` column area to preserve the familiar table shape, but it must not show synthetic account codes.

Rationale: COA account rows would be misleading because operational modules are not fully journal-posted. Bucket grouping matches the current Neraca approach and still gives users an audit path through transactions.

Alternative considered: map buckets to synthetic account numbers. Rejected because it would create false precision and future migration friction.

### D3: Build ledger rows from normalized operational movement events

Create a report service that normalizes eligible operational records into movement events, then groups and sorts them by bucket/date/reference. Each event should carry:

- bucket key and bucket label
- transaction date
- source type
- source reference number
- description
- debit amount
- credit amount
- optional tag/party metadata when available

The same normalized events should feed the screen and XLSX export.

Rationale: a movement-event layer keeps calculation rules testable and prevents UI/export drift.

Alternative considered: query and render each model separately in the Livewire component. Rejected because it would duplicate ordering, balance, export, and filter logic.

### D4: Define debit and credit as bucket-direction columns

Because this is not double-entry accounting, debit and credit must be defined per bucket:

- For `Kas & Bank dari Transaksi`, debit means cash/bank inflow and credit means cash/bank outflow.
- For `Piutang Usaha`, debit means receivable created and credit means receivable reduced by payment or return.
- For `Hutang Usaha`, credit means payable created and debit means payable reduced by payment or return.
- For revenue-like buckets, credit means operational revenue created and debit means return/reversal.
- For purchase/expense-like buckets, debit means operational cost created and credit means return/reversal.

Rationale: retaining debit/credit columns makes the report recognizable, but the semantics must be constrained to operational bucket movement rather than journal postings.

Alternative considered: replace debit/credit with `Masuk` and `Keluar`. Rejected for the first version because the sample and expected report shape use debit/credit.

### D5: Calculate opening and ending balances from event history

For a selected date range, the service should calculate:

```text
saldo_awal = net bucket movement before start_date
period_debit = debit movement between start_date and end_date
period_credit = credit movement between start_date and end_date
running_balance = saldo_awal + period row effects in date order
saldo_akhir = final running_balance
```

A bucket should be shown when it has period movement or a non-zero beginning/ending balance.

Rationale: users need both activity and balance context. Hiding buckets with non-zero balances would make the report less useful for audit.

Alternative considered: show only buckets with period movement. Rejected because it hides non-zero balances during quiet periods.

### D6: Reuse established operational eligibility rules

Eligible data should follow the existing report semantics:

- Sales count when status is `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`.
- Purchases count when status is `RECEIVED`, `RETURNED PARTIALLY`, or `RETURNED`.
- Sale and purchase returns count only when completed.
- Expenses count only when approved and not archived.
- Payment rows count only when active/non-invalidated where the model supports payment status.
- All records must be scoped to the active `setting_id`.

Rationale: these rules are already used by operational financial reports and avoid counting drafts, rejected documents, or incomplete lifecycle states.

Alternative considered: include approved-but-incomplete documents. Rejected because it would overstate operational movement.

### D7: Defer detailed inventory movement

Do not include detailed `Persediaan Barang` movement rows in the first version unless a reliable historical movement source is identified during implementation. If inventory appears at all, it should be a clearly limited summary and must not claim transaction-level stock ledger accuracy.

Rationale: Neraca currently uses current stock valuation as a limitation. Buku Besar movement rows would imply historical accuracy that the current product quantity/cost snapshot cannot provide.

Alternative considered: derive inventory movement from purchase and sale documents. Rejected for first version because returns, POS split ownership, replacement flows, and stockless items make this easy to misstate.

## Risks / Trade-offs

- [Risk] Users may interpret debit/credit as true accounting journals. -> Mitigation: include source note, omit account codes, and define bucket-direction semantics in tests/docs.
- [Risk] Opening balances may be expensive for large datasets because they scan all prior operational events. -> Mitigation: keep queries scoped by setting, date, status, and source table indexes; add focused performance-oriented tests only if current report patterns suggest risk.
- [Risk] Some legacy return/payment rows use different money units. -> Mitigation: reuse the normalization rules already hardened in Neraca and add regression tests for legacy/livewire purchase return payment scaling where relevant.
- [Risk] The report may not tie exactly to Neraca for inventory or equity because it omits detailed inventory and does not derive equity. -> Mitigation: present it as transaction movement by bucket, not as a full trial balance.
- [Risk] Export can drift from the screen. -> Mitigation: one service/value object feeds both and tests assert export parity for representative rows.

## Migration Plan

Deployment is additive: add the route, controller/view entry, Livewire component, service/value objects, export class, landing card route activation, and tests. No database migration is required.

Rollback is straightforward: remove the new route/component/service/export and restore the Buku Besar landing card to placeholder state.

## Open Questions

- Should the first implementation expose bucket filtering in the main filter row, only in the drawer-style secondary filter, or both?
- Should the first implementation include a `Tag` column only when source data has reliable tag metadata, or leave it blank for parity with the sample?
- Should payment method be included in the description for cash/bank events to improve audit readability?
