## Context

`/profit-loss-report` currently renders `App\Livewire\Reports\ProfitLossReport`, which always passes `session('setting_id')` into `OperationalProfitLossReportService`. The service requires a single integer setting ID and each source query filters with `where('setting_id', $settingId)`, so the report can only show the active company.

The screen and `ProfitLossReportExport` already share `OperationalProfitLossReportService`, which is the right integration point. Other report screens use either a global boolean or a single `scopeSettingId`; this change needs a more precise scope because users want to choose multiple companies, not only current-vs-all.

All companies in this installation use IDR, and any user with `reports.access` may view multi-company profit/loss results.

## Goals / Non-Goals

**Goals:**

- Add a multi-company setting selector to the existing Laporan Laba Rugi page.
- Default to the active session setting when no explicit selection is made.
- Calculate screen and export totals from the same selected setting IDs.
- Support one selected setting, multiple selected settings, and all selected settings.
- Label all-selected reports as `Semua Perusahaan`.
- Keep the report currency as IDR.
- Preserve the existing route, permission, date filters, operational rows, and export action.

**Non-Goals:**

- No new report route for global Laba Rugi.
- No new permission beyond `reports.access`.
- No currency conversion or per-company currency breakdown.
- No database schema changes.
- No rewrite of the existing operational profit/loss formula.
- No selectable accounting-ledger scope or chart-of-account reporting.

## Decisions

### D1: Represent report scope as selected setting IDs

Use an array property such as `selectedSettingIds` in `ProfitLossReport`. On mount, load available settings with IDs and company names. When the array is empty, resolve the effective scope to `[session('setting_id')]`.

Rationale: an explicit list is safer and more expressive than a boolean `isGlobal`. It supports current setting, selected subset, and all settings without relying on `null` to mean unrestricted data access.

Alternative considered: add a simple global toggle and pass `null` into the service. Rejected because the user now wants selected-company reporting, and nullable scope is easier to misuse accidentally.

### D2: Update the report service boundary to accept an array

Change `OperationalProfitLossReportService::generate()` to accept `array $settingIds` rather than `int $settingId`. Each operational source query should apply `whereIn('setting_id', $settingIds)` after normalizing the IDs to unique positive integers.

Rationale: the service is shared by the screen and export, so putting scope handling there keeps parity and makes tests straightforward.

Alternative considered: keep the service single-setting and sum multiple service calls in Livewire/export. Rejected because it duplicates aggregation behavior and increases the risk that screen and export drift.

### D3: Export receives the same selected setting IDs as the screen

`ProfitLossReport::exportFilters()` should include `settingIds`, and `ProfitLossReportExport` should pass those IDs into the shared report service. The export header should use:

- the selected company name for exactly one selected/effective setting,
- `Beberapa Perusahaan` for a subset containing more than one but fewer than all settings,
- `Semua Perusahaan` when every available setting is selected.

Rationale: export should faithfully represent the same report scope as the screen and should not imply the active session company when the data is consolidated.

Alternative considered: always show the active company name in export headers. Rejected because it is misleading for multi-company reports.

### D4: Keep authorization simple

The existing `reports.access` gate remains the only access requirement for the page and for multi-company selection.

Rationale: the user explicitly decided that `reports.access` users may see global/multi-company results, and the existing route is already gated by this permission.

Alternative considered: introduce a new `reports.global.access` permission. Rejected because it is out of scope and conflicts with the requested access model.

## Risks / Trade-offs

- [Risk] Empty `selectedSettingIds` could be interpreted as all settings. -> Mitigation: define empty selection as current session setting only, and require explicit all-selection for `Semua Perusahaan`.
- [Risk] Large numbers of settings could make the selector unwieldy. -> Mitigation: start with a Bootstrap-friendly multi-select/list suitable for the current setting count; searchable UI can be added later if needed.
- [Risk] Export headers can become ambiguous for partial multi-company selection. -> Mitigation: use `Beberapa Perusahaan` for partial multi-select and optionally include selected names in a later enhancement if needed.
- [Risk] `whereIn` with an empty array could return no rows or behave unexpectedly depending on query builder usage. -> Mitigation: normalize to current setting before calling the service and guard the service against empty normalized IDs.
- [Risk] Tests may miss export/screen parity if only the service is tested. -> Mitigation: add focused tests for Livewire selected setting IDs and export filter propagation.
