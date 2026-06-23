## Why

The Reports landing page already exposes `Pajak penjualan` as a permitted but disabled placeholder, and the sample files under `report-sample/pajak-penjualan` define the expected page, grouped tax totals, and CSV/XLSX export shapes. Implementing this report gives users a tax-facing summary of taxable sales and purchases for a selected period without requiring manual cross-checking from separate sales and purchase reports.

## What Changes

- Add an actionable `Laporan Pajak Penjualan` report page under the Reports > Pajak category.
- Support date range filtering and period presets consistent with the sample report.
- Aggregate taxable Sales detail rows as `Penjualan` and taxable Purchase detail rows as `Pembelian`, grouped by tax identity/name.
- Display each tax group with DPP, tax rate, total tax, and a subtotal equal to sales tax minus purchase tax for that tax group.
- Add CSV and XLSX exports matching the sample semantics: CSV as flat rows, XLSX as a titled grouped workbook with company name, period, currency note, group headers, subtotal rows, and numeric formatting.
- Keep the report tenant-scoped to the active setting and gated by the existing `reports.access` permission.
- Replace the current disabled `Pajak penjualan` placeholder card with an actionable card that links to the new report.

No breaking changes are expected.

## Capabilities

### New Capabilities

- `sales-tax-report`: Provides the Pajak Penjualan report page, filters, grouped tax calculations, and CSV/XLSX exports.

### Modified Capabilities

- `reports-landing-navigation`: Changes the `Pajak penjualan` card from a disabled placeholder into an actionable report link for users with `reports.access`.

## Impact

- Affected modules: `Modules/Reports`, `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, and report Blade views.
- Affected navigation: Reports landing Pajak tab card configuration.
- Affected data sources: `sales`, `sale_details`, `purchases`, `purchase_details`, `taxes`, and active `setting_id` scope.
- Affected permissions: reuses existing `reports.access`; no new permission is introduced.
- Tests should cover access, landing-card behavior, date filtering, tenant scoping, sales/purchase aggregation, subtotal math, tax display name versus rate, empty state, and CSV/XLSX export parity.
