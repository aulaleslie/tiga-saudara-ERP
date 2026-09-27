## Why

Users need an `Arus kas` report that matches the familiar Mekari/Jurnal-style cash flow shape shown in the report samples, but the ERP currently exposes `Arus kas` only as a placeholder under Reports > Sekilas bisnis. The existing financial report direction is operational rather than full accounting-journal based, so this change should provide a practical cash-flow report from existing operational cash movement sources while clearly stating its source limitations.

## What Changes

- Add an operational `Arus kas` report under Reports > Sekilas bisnis for users with `reports.access`.
- Generate direct-method cash flow sections for operating, investing, and financing activities from supported operational cash movement data.
- Support date range filtering with period presets, active-setting scoping, and company currency display.
- Show opening cash, net cash increase/decrease, bank revaluation placeholder, and ending cash rows.
- Add XLSX and CSV exports whose structure matches the on-screen rows and the provided `report-sample/arus-kas` examples.
- Include a clear note that the report is calculated from operational transactions and does not yet use complete accounting journals, chart-of-account posting, bank ledger balances, or opening capital records.
- Convert the Reports landing `Arus kas` card from a placeholder into an active report link.
- Defer PDF export, custom templates, indirect method, comparison periods, account drill-down, and real bank/COA ledger sourcing.

## Capabilities

### New Capabilities

- `operational-cash-flow-report`: Provides the `Arus kas` report calculated from operational cash movement events, with filtering, display, and XLSX/CSV exports.

### Modified Capabilities

- `reports-landing-navigation`: Changes the `Arus kas` card under Reports > Sekilas bisnis from a placeholder into an actionable report link for users with `reports.access`.

## Impact

- Affected code areas: Reports landing configuration, Reports routes/controller methods, report Blade wrapper, Livewire report component, report service/value objects, XLSX/CSV export class, and focused feature/service tests.
- Data sources: sale payments, purchase payments, sale return payments, purchase return payments, approved expenses, settings, currencies, and any existing source-document tag metadata included in the supported filters.
- Permissions: reuse `reports.access`; no new permission is expected.
- Database: no schema migration is expected for the first operational version.
- Exports: use existing Maatwebsite Excel patterns for XLSX and CSV.
