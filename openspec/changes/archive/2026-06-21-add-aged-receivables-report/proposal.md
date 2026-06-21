## Why

The Reports landing already advertises `Usia piutang`, but it is still a placeholder. Users need a customer-level aged receivables report matching the sampled Mekari/Jurnal-style output so they can see outstanding customer balances by age bucket as of a selected date.

## What Changes

- Add an actionable `Usia piutang` report under Reports > Penjualan for users with `saleReports.access`.
- Add an as-of-date report that groups outstanding customer receivables into `1 - 30 Hari`, `31 - 60 Hari`, `61 - 90 Hari`, and `> 90 Hari` buckets.
- Calculate balances from sales minus active sale payments dated on or before the selected as-of date, matching the current customer receivables report balance basis for the first implementation.
- Exclude customers whose rounded total outstanding balance is zero.
- Provide CSV, XLSX, and PDF exports whose row values match the filtered report result, with CSV remaining plain tabular data and XLSX/PDF carrying report metadata and subtotal presentation.
- Preserve existing `Piutang pelanggan` invoice-detail behavior; `Usia piutang` is a separate summary report, not a replacement.
- Defer completed sales-return or credit-memo allocation from aging buckets unless a later change explicitly defines allocation rules.

## Capabilities

### New Capabilities

- `aged-receivables-report`: Customer-level aged receivables report with as-of filtering, transaction-date aging buckets, tenant scoping, exports, and report permissions.

### Modified Capabilities

- `reports-landing-navigation`: Replace the `Usia piutang` placeholder card with an actionable report card linked to the new aged receivables route.

## Impact

- Affected code:
  - `Modules/Reports/Routes/web.php`
  - `Modules/Reports/Http/Controllers/ReportsController.php`
  - new Reports controller/view for aged receivables
  - new Livewire report component and Blade view under `app/Livewire/Reports` and `resources/views/livewire/reports`
  - new report filter/query/snapshot service classes under `app/Services/Reports`
  - new export class under `app/Exports`
  - focused feature/Livewire/export tests under existing report test locations
- Permissions: reuse `saleReports.access`; no new permission is required.
- Data model: no schema changes expected.
- Dependencies: reuse Laravel, Livewire 3, Eloquent, and Maatwebsite Excel already used by other Reports module exports.
