## Why

Laporan Laba Rugi already lets users select one or more business sources, but the other reports under the Sekilas Bisnis tab still calculate only from the current `session('setting_id')`. This makes the Sekilas Bisnis reporting experience inconsistent and prevents users from viewing Neraca, Buku Besar, Arus Kas, and Neraca Saldo across the same selected business scope.

## What Changes

- Add a Laporan Laba Rugi-style business source selector to these Sekilas Bisnis reports:
  - Neraca / Operational Balance Sheet
  - Buku Besar / Operational General Ledger
  - Arus Kas / Operational Cash Flow
  - Neraca Saldo / Operational Trial Balance
- Keep default behavior unchanged: when no business is selected, each report uses the current `session('setting_id')`.
- Apply selected business scope to screen rendering and every export path for these reports.
- Apply selected business scope to both opening/beginning balances and in-period/as-of movement calculations.
- Show the selected business scope in the report header and exports using the existing Laba Rugi convention: single company name, `Semua Perusahaan`, or `Beberapa Perusahaan`.
- Preserve the current `reports.access` permission behavior; no new global-report permission is introduced.
- Preserve existing IDR currency labeling and all current operational report calculation rules.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `operational-balance-sheet-report`: Neraca must support selectable business source scope for screen and export calculations.
- `operational-general-ledger-report`: Buku Besar must support selectable business source scope for movement events, bucket balances, screen output, and export output.
- `operational-cash-flow-report`: Arus Kas must support selectable business source scope for opening cash, period cash movement, screen output, XLSX export, and CSV export.
- `operational-trial-balance-report`: Neraca Saldo must support selectable business source scope for opening balances, period movement, screen output, XLSX export, and CSV export.

## Impact

- Affected Livewire components:
  - `app/Livewire/Reports/OperationalBalanceSheetReport.php`
  - `app/Livewire/Reports/OperationalGeneralLedgerReport.php`
  - `app/Livewire/Reports/OperationalCashFlowReport.php`
  - `app/Livewire/Reports/OperationalTrialBalanceReport.php`
- Affected report views under `resources/views/livewire/reports/`.
- Affected report services:
  - `OperationalBalanceSheetReportService`
  - `OperationalGeneralLedgerReportService`
  - `OperationalCashFlowReportService`
  - `OperationalTrialBalanceReportService`
  - `OperationalMovementEventService`
- Affected export classes for the four reports, including CSV export paths.
- Tests need targeted cross-business coverage for default scope, selected multi-business scope, excluded unselected businesses, opening balance calculations, and export parity.
