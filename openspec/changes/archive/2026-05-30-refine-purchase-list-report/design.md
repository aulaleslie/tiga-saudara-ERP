## Context

`/reports/purchase-report` currently renders `Daftar Pembelian` through `App\Livewire\Reports\PurchaseReport` with shared filter validation and query services. The existing report is purchase-header oriented: one row per `purchases` record, with filters for date range, supplier, tag, tax, delivery status, and payment status.

The imported sample for `Laporan Daftar Pembelian` is closer to a purchase invoice detail report. It repeats purchase header fields for every product line and includes product/detail columns such as `Nama Produk`, `Kuantitas`, `Satuan`, `Harga per Unit`, line tax, and line totals. The refined report should therefore use purchase detail rows while keeping the existing report route, permission, module conventions, Livewire 3 style, and CoreUI/Bootstrap visual language.

Large production datasets are expected for suppliers and tags, so filters that can grow unbounded must not preload every option into the page or Livewire component.

## Goals / Non-Goals

**Goals:**

- Render `Daftar Pembelian` as a `Faktur Pembelian` detail report with one row per purchase detail/product line.
- Use Bahasa Indonesia for all user-facing labels, buttons, placeholders, filter names, statuses, empty states, and validation messages.
- Default `Tanggal awal` and `Tanggal akhir` to the current calendar month.
- Keep `Nomor Transaksi` and add `Nomor Pembelian Supplier` as separate columns.
- Preserve sample-aligned columns, including `Gudang`, without adding a location filter.
- Display `Gudang` from approved receiving-note locations when available.
- Provide searchable server-side multi-select filters for `Supplier` and `Grup dengan tag`.
- Provide multi-select filters for `Status Dokumen` and `Status Pembayaran`.
- Apply OR semantics within multi-select filters and AND semantics across filter groups.
- Derive payment status from active `purchase_payments` totals.
- Keep existing route and permission behavior.

**Non-Goals:**

- No transaction type selector; this report is fixed to `Faktur Pembelian`.
- No product filter.
- No `Gudang`/location filter.
- No tag AND/OR selector; selected tags always use OR matching.
- No template switching between header and detail reports.
- No database schema change.
- No new permission.
- No external UI framework or pixel-perfect clone of imported sample HTML/CSS.

## Decisions

### Decision 1: Root report rows in `PurchaseDetail`

Build the report result set from `purchase_details` joined/eager-loaded with `purchase`, `purchase.supplier`, `purchase.tags`, `product`, `tax`, and receiving-note location data.

Rationale:
- The sample's columns are line-level. A purchase with multiple products must produce multiple report rows.
- Header-level rows cannot correctly represent `Nama Produk`, `Kuantitas`, `Harga per Unit`, and line tax fields.
- Keeping a single query service preserves the existing report architecture while changing the row grain.

Alternatives considered:
- Keep one row per purchase header and concatenate product names: simpler but does not match the sample report.
- Add a template switch for header/detail modes: useful later but broader than the requested refinement.

### Decision 2: Keep transaction type fixed internally

Remove the user-facing `Tipe transaksi` filter and treat all rows as `Faktur Pembelian`.

Rationale:
- The user confirmed only purchase invoices are in scope.
- A one-option dropdown creates noise and implies unsupported report modes.
- The report contract can still carry an internal fixed value if that helps validation/export naming.

### Decision 3: Use current month as the default period

Initialize `Tanggal awal` to `now()->startOfMonth()` and `Tanggal akhir` to `now()->endOfMonth()` for this report.

Rationale:
- The user clarified current month as the expected default.
- It matches the imported sample better than today's date only.
- It keeps the first report run useful without requiring users to adjust dates.

### Decision 4: Use searchable server-side multi-select for large filters

Supplier and Tag filters should query after a short minimum input length, debounce user typing, limit result counts, and exclude already-selected IDs. Selected values should render as removable pills with labels, not only numeric IDs.

Rationale:
- Supplier and tag tables can contain thousands of records in production.
- Existing single-select dropdown components may preload options and are not safe for this report's scale.
- The current purchase report already has an inline typeahead pattern that can be hardened and polished.

Implementation guidance:
- Require at least 2 characters before lookup.
- Use a 300ms debounce.
- Limit results to a small count such as 10 or 20.
- In non-global mode, scope supplier options to `setting_id`.
- Tag options should search localized Spatie tag names.

### Decision 5: Split document and payment status filters

Expose separate multi-select filters:
- `Status Dokumen`: Draf, Menunggu Persetujuan, Ditolak, Disetujui, Diterima Sebagian, Diterima, Diretur Sebagian, Diretur.
- `Status Pembayaran`: Belum Dibayar, Terbayar Sebagian, Lunas.

Rationale:
- Purchase lifecycle and payment completion are different concepts.
- The current model already stores purchase lifecycle status separately from payment information.
- Multi-select OR behavior lets users ask practical questions such as "unpaid or partially paid approved purchases".

### Decision 6: Derive payment status from active payments

Compute payment status from active `purchase_payments` totals:
- `Belum Dibayar`: active paid amount is less than or equal to zero.
- `Terbayar Sebagian`: active paid amount is greater than zero and less than purchase total.
- `Lunas`: active paid amount is greater than or equal to purchase total.

Rationale:
- The previous purchase report hardening work identified active payment transactions as the source of truth.
- Header `payment_status` can become stale after cancellation, deletion, or invalidation.

### Decision 7: Keep `Gudang` display derived, not filterable

Keep the `Gudang` column and populate it from approved receiving-note locations for the purchase detail when available. If a line has multiple approved receiving locations, show joined location names rather than splitting rows further.

Rationale:
- The sample includes `Gudang`, but the user does not want a location filter.
- Joining names preserves one row per purchase detail and avoids changing quantity/total semantics.
- Splitting by receiving location would require allocation by received quantities and could duplicate monetary totals.

### Decision 8: Keep filters explicit

Changing filters updates pending Livewire state, but report results refresh only when the user clicks `Filter`.

Rationale:
- It avoids expensive report queries while the user is still selecting multiple filters.
- It matches the existing hardened-report snapshot pattern.

## Risks / Trade-offs

- **Line-level report increases row counts** -> Keep pagination, limit eager-loaded relationships, and test purchases with multiple details to verify deterministic counts.
- **Receiving location display can be ambiguous for multiple receipts** -> Join distinct approved location names in the `Gudang` column and avoid monetary row splitting.
- **Payment status derived by aggregate may be slower** -> Use query-level aggregate/subquery logic rather than per-row payment queries.
- **Tag OR behavior may surprise users expecting "must include all"** -> Keep labels simple and avoid showing a tag logic selector.
- **Supplier/Tag selected pills need labels after page rehydration** -> Resolve selected labels from IDs during render/mount or maintain selected option maps.
- **Export parity can drift from screen columns** -> Reuse the same row mapping contract for screen and export paths if exports remain enabled.
- **Existing tests assume header rows** -> Update report tests to assert detail-row grain explicitly.

## Migration Plan

No database migration is expected.

Deployment steps:
- Ship code and tests together.
- Existing `/reports/purchase-report` links continue to work.
- Existing permission `purchaseReports.access` remains the access gate.
- If a rollback is required, revert the report component/query/view changes; no data rollback is needed.

## Open Questions

- None currently blocking. Conservative defaults are defined for unavailable sample columns: display `-` or an empty value rather than fabricating data.
