## Context

The current Reports landing page includes a Pajak tab with `Pajak penjualan` represented as a disabled placeholder for users with `reports.access`. The sample files in `report-sample/pajak-penjualan` define a compact tax report: a date-filtered page titled `Laporan Pajak Penjualan`, grouped rows by tax name, transaction-type rows for `Penjualan` and `Pembelian`, per-tax subtotals, and CSV/XLSX exports.

The application already has a Reports module, Livewire report components, query services, snapshot-validated exports, and Maatwebsite Excel exports. Sales and purchase list reports already expose the key data sources: `sale_details.product_tax_amount`, `purchase_details.product_tax_amount`, related `tax_id`, tax display names, document dates, and active `setting_id` scoping.

## Goals / Non-Goals

**Goals:**

- Implement a first-class `Laporan Pajak Penjualan` page under Reports > Pajak.
- Reuse the existing Reports module, Livewire, query-service, export, permission, and landing-page patterns.
- Aggregate persisted sale and purchase tax amounts without recomputing tax from current tax settings.
- Preserve the distinction between tax display name and numeric tax rate; for example, the sample displays `PPN 12%` with `Rate Pajak` `11.0`.
- Match the sample output structure for UI, CSV, and XLSX where source files exist.
- Keep data scoped to the authenticated user's active `setting_id`.

**Non-Goals:**

- Do not introduce new tax tables, migrations, or backfills.
- Do not change tax calculation behavior in sales, purchases, POS, imports, returns, or stock flows.
- Do not implement withholding tax.
- Do not include sales returns, purchase returns, or other tax adjustment documents in the first version because the sample only contains `Penjualan` and `Pembelian` rows.
- Do not implement PDF export unless a separate PDF requirement/sample is provided.

## Decisions

### Decision 1: Add a dedicated sales tax report capability and service

Create a new Livewire report component and report query service for `sales-tax-report` instead of folding this into the existing sales or purchase reports.

Rationale: The report intentionally combines sales and purchases into one tax summary and has a grouped output shape that does not match either list report. A dedicated service keeps the aggregation contract explicit and easy to test.

Alternative considered: extend the existing sales report with purchase rows. Rejected because it would blur report ownership and permission behavior, and it would not fit the existing sales report's detail/header modes.

### Decision 2: Aggregate from persisted detail tax rows

Build two aggregate streams:

- `sale_details` joined to `sales` and `taxes`, emitted as transaction type `Penjualan`.
- `purchase_details` joined to `purchases` and `taxes`, emitted as transaction type `Pembelian`.

Each stream should group by tax identity and transaction type, summing DPP and tax amount from persisted detail values. Rows with no `tax_id` and zero tax amount should be excluded.

Rationale: Existing import and transaction flows persist explicit line tax amounts. Reporting from persisted values avoids drift from tax master changes and follows the existing sales/purchase report behavior.

Alternative considered: recompute DPP and tax from current `taxes.value`. Rejected because historical transactions may have been created under different tax rules, and the sample shows display-name/rate semantics that can diverge.

### Decision 3: Derive DPP consistently from persisted line amounts

For each taxable detail row, derive DPP as `max(0, sub_total - product_tax_amount)` and total tax as `product_tax_amount`. The implementation should not subtract document-level discounts unless a later sample or requirement defines allocation rules for discounts in this tax summary.

Rationale: Existing report mappings use the same line-level DPP formula, and it is directly testable against persisted detail rows. Allocating document-level discounts across tax groups would be broader than the sample and could change totals in ways users cannot trace.

Alternative considered: use sale/purchase header totals minus header tax. Rejected because the report groups by tax identity and transaction type, which requires detail-level tax identity.

### Decision 4: Include approved/posted operational documents only

Sales should be included when they are approved or later in the sale lifecycle, excluding drafted, waiting-approval, rejected, and archived records. Purchases should follow the same principle: approved or later, excluding drafted, waiting-approval, rejected, and archived records.

Rationale: Tax reporting should not include unapproved working documents. The existing lifecycle constants provide a clear boundary without introducing new status fields.

Alternative considered: include all non-rejected records. Rejected because waiting-approval documents can still change or be rejected and should not affect tax reporting.

### Decision 5: UI and exports share one normalized row model

The query service should return normalized rows with:

- tax id/name/rate
- transaction type
- DPP
- total tax

The Livewire view can group rows by tax name for display and subtotal calculation. CSV should emit only flat data rows with headings `Nama Pajak`, `Transaksi`, `DPP`, `Rate Pajak`, `Total Pajak`. XLSX should include metadata rows, table headings, tax group headers, transaction rows, blank separators, and per-tax subtotal rows.

Rationale: A normalized row model keeps UI, CSV, and XLSX parity testable while allowing each export format to match the sample structure.

Alternative considered: make the export class re-query independently. Rejected because it risks drift between screen and downloads.

### Decision 6: Replace the landing placeholder with an actionable card

Keep the `Pajak penjualan` card under the Pajak tab and keep its permission as `reports.access`, but remove placeholder treatment and link it to the new report route.

Rationale: The historical report-navigation spec deliberately introduced this as a future report placeholder. This change completes that path without introducing a new permission.

Alternative considered: gate the report by both `saleReports.access` and `purchaseReports.access`. Rejected for this proposal because the existing placeholder is already mapped to `reports.access`, and the report is a general tax summary rather than a transaction list.

## Risks / Trade-offs

- Tax-report totals may differ from manually combined sales/purchase list totals if users expect header-level discounts to be allocated across tax groups. Mitigation: document and test the persisted-detail DPP rule; add discount allocation only with a separate requirement.
- Historical rows with a missing `tax_id` but non-zero `product_tax_amount` may be hard to group. Mitigation: group them under an explicit fallback label such as `Tanpa nama pajak` while preserving the persisted rate when available.
- The sample contains a PDF link but no PDF artifact. Mitigation: implement CSV/XLSX parity now and leave PDF disabled or absent until a PDF-specific requirement exists.
- Large date ranges can scan sale and purchase detail tables. Mitigation: use aggregate SQL with date/status/setting filters and avoid loading full detail models for the summary.
- The report combines sales and purchases under `reports.access`, which may expose purchase-side tax totals to users who have general report access but not purchase list access. Mitigation: keep this aligned with the existing Pajak placeholder permission; revisit only if business requires stricter tax-report permissions.

## Migration Plan

No database migration is required.

Deploy as additive code:

1. Add the report route, controller/view entry, Livewire component, query/filter/snapshot services, and export class.
2. Update the Reports landing configuration to make `Pajak penjualan` actionable.
3. Add focused feature and Livewire/export tests.
4. Rollback is code-only: remove the new route/component/services/export and restore the placeholder card.

## Open Questions

- Should a future version allocate document-level discounts into DPP per tax group?
- Should rows with non-zero persisted tax amount but missing tax identity be shown under a fallback group or excluded?
- Should PDF export be added once a PDF sample is available?
