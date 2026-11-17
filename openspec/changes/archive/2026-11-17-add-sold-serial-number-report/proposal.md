# Proposal

## Why

Users need a product-filtered list of sold serial numbers for a sale period, showing the serial currently representing each sale after physical returns and approved replacements. Existing product sales reports aggregate quantities and values and do not provide this five-column serial view.

## What Changes

- Add a sold serial number report under Reports with searchable multiple-product selection, required inclusive sale-date range, existing sales-report business scope, paginated preview, and XLSX export.
- Present exactly Product name, SN, Tgl, Bulan, Tahun, ordered by original sale date, product name, and SN.
- Include approved dispatch serials from regular sales and POS, including selected bundle components; use current component product names.
- Remove received returned serial occurrences; substitute their latest approved replacement serial while retaining the original sale date. Pending return approval alone does not remove a serial. Resales belong to their new sale.
- Keep previous periods responsive to later return/replacement outcomes; this is a current-outcome projection, not an as-of ledger.
- Use focused verification for serial lifecycle cases, date/product/business boundaries, permission enforcement, and preview/export parity.

## Capabilities

### New Capabilities

- `sold-serial-number-report`: Product-filtered sold serial projection with received-return exclusion, replacement-chain substitution, original sale dates, and matching preview/XLSX output.

### Modified Capabilities

None. Existing aggregate sales reports, reporting-date overrides, return custody, and transaction lifecycles retain their requirements.

## Impact

- Proposed additions in `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, report Blade views, and `Modules/Reports` controller/routes/navigation.
- Read existing sales, approved dispatch details, product/serial records, return receipt evidence, and standard/POS replacement lineage without mutating transactions.
- Reuse Laravel 10, Livewire 3, existing business-scope authorization, `saleReports.access`, and Maatwebsite Excel. No new package or schema migration is planned.
- Add focused feature/Livewire/export coverage; no full-suite or benchmark expansion is required unless focused checks uncover a wider issue.
