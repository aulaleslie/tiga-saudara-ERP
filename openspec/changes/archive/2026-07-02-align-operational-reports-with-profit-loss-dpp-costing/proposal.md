## Why

Operational reports under `/reports` currently disagree with the corrected Laporan Laba Rugi basis. Laba Rugi now treats the current sale document as authoritative, calculates sales from sale-detail DPP, and calculates Beban Pokok Pendapatan from sale-detail cost snapshots, while Neraca, Buku Besar, Neraca Saldo, and Arus Kas still rely on mixed sale headers, purchase totals, and sale return adjustments in ways that can overstate revenue/costs or double-count returns.

## What Changes

- Align Buku Besar operational revenue events with Laporan Laba Rugi by using sale detail DPP (`sale_details.sub_total - sale_details.product_tax_amount`) instead of sale header `total_amount`.
- Align Buku Besar operational HPP/cost events with Laporan Laba Rugi by using `sale_details.cost_unit_snapshot * sale_details.quantity` for Beban Pokok Penjualan/Pendapatan instead of completed purchase totals.
- Stop using completed `sale_returns` as separate revenue or HPP adjustment sources for reports whose sales basis is the current corrected sale document.
- Preserve payment-based cash and receivable/payable movement events so cash, AR, AP, purchase payments, sale payments, and return payments remain auditable operational movements.
- Align Neraca Saldo with the same operational event semantics because it is built from the shared movement event source.
- Review and align Neraca receivable calculations so receivables do not double-subtract sale returns when the current sale document already reflects post-return values.
- Keep Arus Kas cash-basis; do not introduce non-cash DPP or HPP rows, but ensure its return-payment treatment and source note remain consistent with the authoritative-sale-document rule.
- Preserve existing report routes, permissions, filters, export entry points, and operational/non-ledger positioning.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `operational-general-ledger-report`: Change operational revenue and cost event requirements so Buku Besar follows sale-detail DPP revenue and sale-detail cost snapshot HPP semantics, while avoiding sale-return double counting.
- `operational-trial-balance-report`: Change Neraca Saldo requirements so debit/credit rows derived from operational movement events use the same DPP revenue and cost snapshot HPP semantics as Buku Besar.
- `operational-balance-sheet-report`: Change Neraca requirements so receivables and related operational balances remain consistent with current corrected sale documents and do not double-subtract sale returns.
- `operational-cash-flow-report`: Clarify that Arus Kas remains cash-basis and is not expected to show non-cash DPP/HPP, while keeping return-payment treatment and explanatory notes consistent with the reporting basis.

## Impact

- Affected services: `App\Services\Reports\OperationalMovementEventService`, `OperationalGeneralLedgerReportService`, `OperationalTrialBalanceReportService`, `OperationalBalanceSheetReportService`, and `OperationalCashFlowReportService`.
- Affected report UI/export surfaces: operational Buku Besar, Neraca Saldo, Neraca, and Arus Kas Livewire screens and Excel exports where applicable.
- Affected tests: focused service, Livewire, and export tests for operational general ledger, trial balance, balance sheet, cash flow, and parity with the existing Laporan Laba Rugi DPP/HPP rules.
- No database migrations, new dependencies, route changes, or permission changes are expected.
