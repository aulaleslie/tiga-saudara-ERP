## Context

The ERP currently has two purchase-facing list surfaces with different purposes:

- `/purchases` is the operational purchase index backed by `App\Livewire\Purchase\PurchaseTable`. It shows one row per purchase, has summary-card filters, and already uses `purchases.due_date` for overdue filtering, but does not display the due date column.
- `/reports/purchase-report` is `Laporan Daftar Pembelian` backed by `App\Livewire\Reports\PurchaseReport`. It currently renders one row per `purchase_details` record, repeats purchase header fields, includes `Tanggal Jatuh Tempo`, validates filters through `PurchaseReportValidator`, builds rows through `PurchaseReportQueryService`, snapshots applied filters before export, and exports through `PurchaseReportExport`.

The requested behavior keeps the detail-line report as the default while adding a header-level report mode for concise invoice review. This is an additive reporting change and should preserve the existing route, permission names, module structure, export actions, and database schema.

## Goals / Non-Goals

**Goals:**

- Show `Tanggal Jatuh Tempo` on the normal `/purchases` purchase list.
- Keep `Detail` as the default `Daftar Pembelian` report mode.
- Add a `Header` report mode that returns one row per purchase invoice.
- Make screen columns, sorting, pagination, applied-filter snapshotting, and Excel/CSV exports respect the selected report mode.
- Persist report mode through URL and/or session so it remains stable during refresh, pagination, sorting, and export.
- Reuse existing purchase report filtering semantics for supplier, tag, document status, payment status, date basis, and access scope.

**Non-Goals:**

- No schema migration.
- No new route or permission.
- No PDF export enablement.
- No product-level columns in header mode.
- No new product, warehouse, or location filters.
- No change to the existing detail-mode column contract except where mode-specific branching is needed.

## Decisions

### Decision 1: Treat report mode as part of the validated filter state

Add a `reportMode` value with supported values `detail` and `header`. Include it in `PurchaseReportFilterData`, validator rules, applied filters, query-string/session persistence, and filter hashing.

Rationale:
- Export safety depends on the snapshot hash matching the exact result contract used on screen.
- A user who changes mode after filtering should re-run `Filter` before export, the same as other pending filter changes.
- Including mode in the filter DTO keeps count, pagination, sort, and export flows deterministic.

Alternatives considered:
- Store mode only in Livewire component state: simpler, but exports could accidentally use a different shape than the applied results.
- Use two separate report routes: clearer URLs, but unnecessary route and permission churn.

### Decision 2: Keep one report component and branch the query/mapping by mode

Keep `PurchaseReport` as the single Livewire surface. Evolve the report query/export layer to support both result grains:

```text
reportMode=detail
└── root query: PurchaseDetail
    └── mapper: existing detail columns

reportMode=header
└── root query: Purchase
    └── mapper: concise header columns
```

Rationale:
- The same filters, access scope, export gating, and UI shell apply to both modes.
- Detail mode already has a mature query/mapping/export path that should remain stable.
- Header mode needs a different root model to avoid duplicate rows and unnecessary product joins.

Alternatives considered:
- Force header mode by grouping detail rows in PHP: easier to bolt on, but paginating grouped rows from detail results is fragile and expensive.
- Duplicate a second Livewire component: cleaner separation, but creates duplicated filter/export logic.

### Decision 3: Use concise header columns

Header mode should show invoice-level columns only:

- `Tanggal`
- `Nomor Transaksi`
- `Nomor Pembelian Supplier`
- `Nama Panggilan`
- `Status Dokumen`
- `Status Pembayaran`
- `Memo`
- `Total`
- `Sisa Tagihan`
- `Tanggal Jatuh Tempo`
- `Jumlah Kena Pajak`
- `Total Pajak`
- `Pembayaran`
- `No Ref`
- `Tag`

Rationale:
- Header mode exists to answer invoice-level questions quickly.
- Product/detail columns would either be blank or misleading when the row grain is one purchase.

Alternatives considered:
- Use all existing detail-mode columns and leave product fields blank: preserves column count but creates noisy, low-value output.
- Concatenate product names into one header cell: useful sometimes, but can make header rows heavy and does not fit the concise requirement.

### Decision 4: Derive header-mode payment values consistently with detail mode

Header mode should use the same active-payment aggregate as detail mode for `Status Pembayaran`, `Pembayaran`, `Sisa Tagihan`, and filtering by payment status.

Rationale:
- Existing report hardening established active purchase payments as the report source of truth.
- Header and detail modes should not disagree for the same purchase.

Alternatives considered:
- Use `purchases.payment_status`, `paid_amount`, and `due_amount` directly: faster, but these fields may be stale when payments are cancelled, deleted, or invalidated.

### Decision 5: Add due date column to `/purchases` without changing summary-card semantics

Add a visible sortable `Tanggal Jatuh Tempo` column to the operational purchase table, preferably adjacent to `Tanggal` and before amount/balance columns. Continue using existing `due_date` filters for summary-card clicks.

Rationale:
- The index already relies on due dates for overdue filtering, so exposing the date makes the filter results explainable.
- This is a display/sort enhancement, not a lifecycle or payment behavior change.

Alternatives considered:
- Show due date only in overdue filtered results: lower table width cost, but inconsistent and less useful for upcoming obligations.

## Risks / Trade-offs

- Header and detail query logic can drift over time -> Keep shared filter application helpers where practical and add tests that compare both modes for the same filter set.
- Export column contracts can drift from screen columns -> Use mode-specific shared mapping methods for both Blade rendering and exports.
- Snapshot hashes may not catch mode changes if mode is omitted -> Include `reportMode` in `PurchaseReportFilterData::toArray()` and validation.
- Header mode sorting by product columns is invalid -> Restrict/normalize sort fields when switching modes and define header-mode supported sort fields explicitly.
- Query string persistence can expose invalid mode values -> Validate mode and fall back to `detail`.
- Adding another column to `/purchases` increases table width -> Place due date near existing date columns and preserve responsive table behavior.

## Migration Plan

No data migration is required.

Deployment steps:
- Ship code and tests together.
- Existing `/purchases` and `/reports/purchase-report` URLs continue to work.
- Existing users land on detail mode by default unless a valid persisted mode indicates otherwise.
- Rollback is a code-only revert; no data rollback is needed.

## Open Questions

None. The user selected: `/purchases` list due-date column, detail mode default, exports match selected mode, concise header columns, and mode persistence through query string/session.
