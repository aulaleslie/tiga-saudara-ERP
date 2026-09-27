## Why

Purchase, Sales, Global Purchase Payment, and Global Sales Payment list searches return the correct documents but can leave the row three-dot action control unable to display its options. Users then cannot open a result, inspect payment history, or create a payment without clearing the search or navigating away.

## What Changes

- Restore usable three-dot action menus immediately after a list search, search clear, or other Livewire table refresh.
- Apply a small, functionality-first client-side recovery for the existing Alpine and Bootstrap/CoreUI action-menu implementations rather than redesigning the menu system.
- Give dynamically rendered sales rows stable identities so an action menu is not reused for a different search result.
- Add browser-level regression coverage for opening actions after searching on all four affected list views.

## Capabilities

### New Capabilities

- `search-result-action-menu-recovery`: Ensures action menus on dynamically refreshed document-list search results remain available.

### Modified Capabilities

- `document-list-search`: Search composition must preserve accessible row action menus as well as query, filter, sort, and pagination behavior.

## Impact

- Affects `PurchaseTable` and `SaleTable` Livewire views, their action partials, and the Global Purchase Payment dropdown initialization path.
- Affects normal Purchase and Sales lists plus their global-payment variants; no query semantics, routes, permissions, or persistence are changed.
- Requires browser interaction testing because the defect occurs during Livewire DOM morphing and is not observable in server-rendered component tests.
