## Why

Users currently have to leave a customer or supplier record and reselect that party in the standalone global-payment workspace to inspect and settle its cross-business receivables or payables. Customer and supplier records are shared across settings, so their detail pages are a natural additional entry point for the same full global-payment functionality, constrained to the displayed party.

## What Changes

- Add the complete Pembayaran Penjualan Global workspace beneath customer details, fixed to the displayed customer while retaining summary cards, business/date filters, search, sorting, pagination, global detail/history access, and multi-invoice payment creation.
- Add the complete Pembayaran Pembelian Global workspace beneath supplier details, fixed to the displayed supplier with the equivalent purchase-payment functionality.
- Apply business filters to the related sales or purchases within the fixed customer/supplier constraint; customer and supplier discovery remains global and is not scoped by the active setting.
- Reuse the existing global-payment components, eligibility rules, routes, services, and permission split so the standalone Pembayaran Global pages remain available and behaviorally unchanged.
- Keep embedded workspaces read-only for users who have the applicable global-access permission but lack the corresponding payment-create permission.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-sales-multi-payment`: Expose the full global sales-payment workspace from customer detail with a mandatory customer constraint and composable business filtering.
- `global-purchase-multi-payment`: Expose the full global purchase-payment workspace from supplier detail with a mandatory supplier constraint and composable business filtering.

## Impact

- Customer and supplier detail views in `Modules/People`.
- Global-mode sales and purchase Livewire tables and summary-card components.
- Existing global sales/purchase payment navigation context and automated authorization/filtering tests.
- No database migration, new payment ledger, or change to the standalone global-payment pages is expected.
