## Why

The global purchase and sales payment workspaces currently filter by one business and document date only. Finance users need to focus both paid and unpaid documents by payment due date, across one or more businesses, while having a reset action that visibly clears every filter control.

## What Changes

- Add an independently optional, inclusive due-date range to the global purchase- and sales-payment workspaces.
- Replace the single-business selector with a non-searchable multi-business selector; no business selected continues to mean all businesses.
- Keep document-date and due-date ranges independent, so documents must meet every supplied boundary.
- Ensure all applied filters affect the table and its existing summary cards, while fully paid documents remain eligible for due-date filtering.
- Improve the filter-panel layout for two date ranges, retain the summary cards in their current position above it, and make reset visibly clear all controls and applied-filter feedback.
- Persist and restore the expanded applied-filter state through the existing shareable URLs.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-purchase-multi-payment`: Expand the global purchase-payment filtering, state restoration, and filter-panel requirements for multi-business and due-date filtering.
- `global-sales-multi-payment`: Expand the global sales-payment filtering, state restoration, and filter-panel requirements for multi-business and due-date filtering.

## Impact

- Affects the global-mode PurchaseTable and SaleTable Livewire components, their filter-panel Blade views, and purchase/sales summary-card Livewire components.
- Updates URL-backed filter state and filter-change events between tables and summary cards.
- Requires focused Livewire feature coverage for purchase and sales filtering, reset synchronization, state restoration, and summary-card consistency.
