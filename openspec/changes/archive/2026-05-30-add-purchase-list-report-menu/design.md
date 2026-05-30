## Context

The ERP already has two purchase report surfaces. The newer `/reports/purchase-report` route is permission-gated with `purchaseReports.access`, uses `App\Livewire\Reports\PurchaseReport`, and has shared validation/query/export infrastructure. The older `/purchases-report` route remains generic and should not be the foundation for this change.

The desired report is a purchase listing under `Laporan -> Pembelian -> Daftar Pembelian`. It should keep the existing trusted purchase-header report model, but present a more focused report UI: a compact top filter bar, a right-side `Filter lainnya` drawer, and a sample-like export dropdown shell. The feature must stay brownfield-friendly: no route URL change, no new permission, no schema change, and no functional export in v1.

## Goals / Non-Goals

**Goals:**

- Reposition the existing purchase report in the sidebar as `Laporan -> Pembelian -> Daftar Pembelian`.
- Keep `/reports/purchase-report` and `purchaseReports.access`.
- Rename the page title and breadcrumb to `Daftar Pembelian`.
- Default the report date range to today only.
- Introduce a top filter bar with start date, end date, period preset, `Filter`, `Filter lainnya`, and an `Ekspor` dropdown shell.
- Move advanced filters into a right-side drawer:
  - `Tipe transaksi`
  - `Tanggal berdasarkan`
  - `Supplier`
  - `Status Pengiriman`
  - `Status Pembayaran`
- Keep supplier filtering multi-select.
- Keep one row per purchase header.
- Use existing CoreUI/Bootstrap styling and Livewire patterns.

**Non-Goals:**

- No new route path or permission.
- No functional Excel, CSV, or PDF export in v1.
- No purchase-detail row reporting.
- No template switching.
- No external UI framework or pixel-perfect clone of the sample HTML.
- No database schema or migration changes.

## Decisions

### Decision 1: Evolve the existing hardened purchase report route

Use the existing `reports.purchase-report.index` route and `PurchaseReport` Livewire component instead of creating a new report route.

Rationale:
- It preserves existing bookmarks and authorization behavior.
- The current route already has the correct report-specific permission.
- Existing shared report validation and query services reduce risk.

Alternatives considered:
- Add `/reports/purchases/list`: clearer URL, but creates routing churn and duplicated route/menu logic.
- Reuse legacy `/purchases-report`: not preferred because it has older UI/status assumptions and generic `reports.access` authorization.

### Decision 2: Treat `Daftar Pembelian` as a header-level report

Each result row represents one purchase header/invoice, not one product/detail line.

Rationale:
- This matches the chosen scope.
- It avoids adding purchase detail joins and product-level column semantics before the business asks for detail reporting.
- It keeps the current report query and pagination model viable.

Alternatives considered:
- Detail rows per product line: closer to the sample's `Purchase Invoice Detail` template, but explicitly out of scope for v1.
- Template switch between header/detail reports: useful later, but too broad for the initial menu and filter UX change.

### Decision 3: Split delivery and payment status filters

The drawer exposes separate `Status Pengiriman` and `Status Pembayaran` filters.

Rationale:
- The existing purchase list uses `purchases.status` for delivery/lifecycle state and `purchases.payment_status` or active payment totals for payment state.
- Splitting the filters avoids overloading the sample's generic `Status` field.

Implementation notes:
- `Status Pengiriman` should map to canonical purchase statuses:
  - `DRAFTED`
  - `WAITING_APPROVAL`
  - `APPROVED`
  - `REJECTED`
  - `RECEIVED PARTIALLY`
  - `RECEIVED`
  - `RETURNED PARTIALLY`
  - `RETURNED`
- `Status Pembayaran` should use existing payment status semantics already used by the current report/query service.

### Decision 4: Period presets update pending state only

Selecting a period preset updates the pending start/end date fields, but the report query does not run until `Filter` is clicked.

Rationale:
- This matches the clarified requirement.
- It keeps filtering explicit and avoids surprise Livewire queries while users adjust advanced filters.

### Decision 5: Build a right-side drawer with local state

Use a CoreUI/Bootstrap-compatible right-side drawer/offcanvas pattern controlled by Livewire/Alpine-friendly local state.

Rationale:
- The sample uses a right-side filter drawer.
- The drawer keeps advanced filters available without cluttering the report header.
- A local visibility state avoids unnecessary backend writes when opening/closing the drawer.

Alternatives considered:
- Inline collapsible card: simpler, but not the requested interaction.
- Modal: easier with Bootstrap, but less aligned with the sample's persistent side panel behavior.

### Decision 6: Keep export as a disabled UI shell

Render an `Ekspor` dropdown/button shell with disabled options and no export action in v1.

Rationale:
- The user wants the button prepared visually like the sample.
- It avoids implying export is available.
- Existing export logic can be left untouched or hidden from the new UI until a later change re-enables it deliberately.

## Risks / Trade-offs

- **Risk: Hidden legacy report remains confusing** -> Remove or de-emphasize the flat `Laporan Pembelian` menu entry so users only see the nested `Daftar Pembelian` entry.
- **Risk: Delivery status labels diverge from stored status values** -> Centralize labels in the Livewire component or a small helper method and keep filtering on canonical values.
- **Risk: Drawer behavior becomes brittle if implemented with ad hoc JavaScript** -> Prefer Bootstrap/CoreUI-compatible markup and minimal local state; keep filter state in Livewire properties.
- **Risk: Existing export tests assume visible export buttons** -> Adjust only UI expectations for this report change; do not remove backend export infrastructure unless a later cleanup explicitly scopes that work.
- **Risk: Today-only default surprises users used to month-to-date** -> Capture this as an explicit requirement and test it so the behavior is intentional.
