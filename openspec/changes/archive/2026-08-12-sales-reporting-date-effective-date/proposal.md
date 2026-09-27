## Why

An authorized reporting-date override currently changes the visible date on a sale but not its reporting-period inclusion. As a result, a sale assigned a September marketing date is absent from September sales analytics when its immutable source date is outside that month, making reports disagree with the operational sale view.

## What Changes

- Make defined sales reporting and analytical surfaces use a sale's effective reporting date: active `reporting_date` when set, otherwise the original sale `date`.
- Apply that date consistently to each included report's period filtering, date sorting or grouping, displayed transaction date, and exports.
- Include the sales list (including global mode), sales by customer, sold-side sales by product, sales tax, and sales-order-completion reports.
- Preserve distinct date semantics for customer receivables, aged receivables, sales delivery, returns, stock, inventory, and general-ledger reporting.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `reporting-date-overrides`: Extend effective reporting-date behavior to defined sales report surfaces while preserving the documented exclusions for operational, ageing, stock, inventory, and ledger semantics.

## Impact

- Affects sales report query services, Livewire display/export mapping, and focused report tests under `app/Services/Reports`, `app/Livewire/Reports`, `app/Exports`, and `Modules/Reports/Tests/Feature`.
- Reuses the existing nullable `sales.reporting_date` column and immutable audit trail; no data migration, backfill, API change, or alteration of the original sale date is required.
