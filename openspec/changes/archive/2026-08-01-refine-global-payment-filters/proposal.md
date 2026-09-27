## Why

The global sales and purchase payment lists expose date filters that appear to have no effect in normal use, despite the server-side date query being present. Their immediate-update behavior gives users no clear apply point or indication of the active filter state, and the two workspaces do not present the same filter and table hierarchy.

## What Changes

- Add an explicit, shared filter-application interaction to the global sales and global purchase payment lists.
- Present business and document-date controls in a consistent payment-workspace filter panel, with applied-state feedback and a clear reset action.
- Preserve the selected summary-card state visibly while filters refresh summaries and table results.
- Verify date filtering through browser-level interaction coverage in addition to existing Livewire query tests.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `global-sales-multi-payment`: Global sales payment list filters gain an explicit apply workflow, visible applied state, and consistent summary-card selection behavior.
- `global-purchase-multi-payment`: Global purchase payment list filters gain an explicit apply workflow, visible applied state, and consistent workspace presentation.

## Impact

- Affects global-mode `SaleTable` and `PurchaseTable`, their summary-card Livewire components, and their Blade workspace/list views.
- Adds focused browser interaction tests; no payment-allocation, lifecycle, database schema, API, or authorization changes.
