# Proposal

## Why

Purchase and sales detail pages do not show an outstanding balance beneath their totals. Normal lists use stored due amounts while Global Payment lists use active settlement evidence, so the same document can show conflicting balances when its header is stale.

## What Changes

- Show Sisa Pembayaran on purchase detail and Sisa Tagihan on sales detail immediately below Total Keseluruhan, including normal and Global Payment routes.
- Use live, nonnegative outstanding balances consistently on the normal and Global Payment purchase/sales list rows, including embedded supplier/customer workspaces that reuse those tables.
- Align existing outstanding/overdue filters and existing balance sorting with the displayed live balance before pagination.
- Count active payments and, for sales, credit applications attached to active payments; exclude invalidated settlement evidence and avoid currency rescaling.
- Preserve access rules, business scoping, document eligibility, archive handling and settlement write behavior. Reading a page does not reconcile or rewrite a stored header.
- Add focused regression coverage for stale headers, credits, invalidated payments, sorting/filtering and list/detail parity.

## Capabilities

### New Capabilities

- `document-outstanding-balances`: Consistent live outstanding presentation and balance-based list behavior for purchase and sales documents.

### Modified Capabilities

None. Existing payment maintenance and global payment allocation contracts remain unchanged. Summary-card totals and collection metrics are outside this change; their existing capability contracts remain in force.

## Impact

Shared detail views in `Modules/Purchase/Resources/views/show.blade.php` and `Modules/Sale/Resources/views/show.blade.php`; `app/Livewire/Purchase/PurchaseTable.php`, `app/Livewire/Sale/SaleTable.php` and their row templates; reusable model query projections/scopes where required. Existing model live-due accessors and payment conventions remain authoritative. Tests extend normal/global list coverage and add focused detail parity cases. No new permissions, schema migration, dependency or financial mutation endpoint is needed. Legacy Yajra lists not used by these workspaces, reports, exports and POS receipts are outside this scope.
