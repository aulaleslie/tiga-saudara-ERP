## Why

Global Payment currently lists only payable transactions by default, making paid transactions available only through a separate recent-payment card flow. Users need one cross-business workspace that can inspect both paid and payable sales/purchases, narrow the result by business and document date, and reliably find transactions through the identifiers and business context they actually use.

## What Changes

- Expand the default Global Sales Payment and Global Purchase Payment lists to include eligible paid and payable transactions across businesses.
- Preserve the outstanding, overdue, and paid summary cards as composable list filters; fully paid transactions remain read-only and cannot receive another payment.
- Add business and document transaction-date range filters, plus visible business context on global rows.
- Expand global-list search to cover party names, internal transaction numbers, product names, header notes, external sales/purchase numbers, and persisted sales bundle names.
- Preserve existing lifecycle, archival, authorization, balance, and payment-allocation safeguards.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `global-sales-multi-payment`: Change the global sales-payment workspace from outstanding-only to paid-and-payable inspection, with composable business/date/card filters, expanded search, and read-only fully paid rows.
- `global-purchase-multi-payment`: Change the global purchase-payment workspace from outstanding-only to paid-and-payable inspection, with composable business/date/card filters, expanded search, and read-only fully paid rows.

## Impact

- Affects the global sales and purchase payment Livewire tables, summary-card interactions, list views, and their focused feature/Livewire tests.
- Uses existing sale/purchase, payment, setting/business, party, detail-line, and sales-bundle data; no new API, dependency, or ledger is required.
- Existing global payment creation remains limited to eligible transactions with a positive canonical live outstanding balance.
