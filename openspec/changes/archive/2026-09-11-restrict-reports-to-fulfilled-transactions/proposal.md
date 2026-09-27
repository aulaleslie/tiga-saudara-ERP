## Why

Sales- and purchase-related reports currently apply inconsistent lifecycle eligibility: some include drafts or approved-but-unfulfilled documents, some include partial fulfillment, and some separately subtract returns even though return settlement modifies the selected source/target document. This can recognize monetary and product values before operational fulfillment and can double-count return reductions.

## What Changes

- Establish one report eligibility policy: sales are recognized only after full dispatch and purchases only after full receipt.
- Include `RETURNED PARTIALLY` source/target documents because their persisted values represent the remaining transaction value after an approved settlement.
- Exclude partial-dispatch and partial-receipt documents until they become fully fulfilled.
- Exclude fully returned documents once their return lifecycle is completed/archived; do not use the `RETURNED` status alone to exclude a document while settlement remains unfinished.
- Treat the persisted, settlement-modified sale or purchase header and detail values as authoritative and remove separate return subtraction from reports.
- Restrict report status-filter options to lifecycle statuses eligible for that report.
- Apply the same eligibility and value-source rules to on-screen rows, grouped totals, grand totals, pagination queries, local/global setting scopes, and every supported export.
- Apply the policy to primary lists, by-customer/by-supplier, by-product, receivable/payable, sales-tax, and operational financial reports that derive values from sales or purchases.
- Preserve delivery reports' approved dispatch/receiving-event semantics and order-completion reports' intentional pre-fulfillment visibility.
- Treat complete mutation of the selected sale/purchase header and details at settlement approval as an existing domain contract; hardening the sales-return mutation workflow is outside this change.

## Capabilities

### New Capabilities
- `fulfilled-transaction-report-eligibility`: Defines the shared fulfillment, return-state, authoritative-value, scope, and parity rules for sales- and purchase-related reports.

### Modified Capabilities
- `sales-list-report`: Restrict reportable documents and status options to fully dispatched and partially returned sales.
- `purchase-list-report`: Replace all-status reporting with fully received and partially returned purchase eligibility.
- `sale-by-product-report`: Use eligible persisted sale-detail values without separately subtracting sales-return aggregates.
- `purchase-by-product-report`: Use eligible persisted purchase-detail values without separately subtracting purchase-return aggregates.
- `sales-tax-report`: Replace approved-or-later inclusion with fulfilled-transaction eligibility and persisted modified tax values.

## Impact

- Primary query services, validators, Livewire filter options, snapshot/filter hashing, totals, and exports under `app/Services/Reports`, `app/Livewire/Reports`, and `app/Exports`.
- Report behavior for sales and purchases that are drafted, waiting approval, approved but unfulfilled, partially fulfilled, partially returned, fully returned, or archived.
- Existing OpenSpec report requirements that currently allow all purchase statuses or approved-or-later tax rows.
- No schema change, public API change, or return-workflow mutation hardening is planned.
