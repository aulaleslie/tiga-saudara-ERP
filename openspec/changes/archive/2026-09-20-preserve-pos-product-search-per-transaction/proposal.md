# Proposal

## Why

The POS product-search modal currently clears its query and results every time it opens, forcing users to repeat the same search after selecting each product. Preserving search state within the current transaction makes repeated product entry faster while still starting each newly established transaction context with a clean search.

## What Changes

- Preserve the `Cari Produk` modal query and rendered results when the modal is closed, a product is selected, and the modal is reopened during the same POS transaction.
- Clear product-search state only after a successful checkout, a successful save-as-draft-and-new operation, a successful cart clear (`Kosongkan Keranjang`), or loading a drafted POS transaction.
- Preserve product-search state when those boundary operations fail or are cancelled, and during non-boundary cart actions such as removing individual products or changing the customer.
- Invalidate outstanding product-search requests when resetting state so a late response cannot repopulate a new transaction's search results.
- Apply checkout reset behavior to both regular and staged/multi-payment completion flows.

## Capabilities

### New Capabilities

- `pos-product-search-lifecycle`: Defines how the POS product-search query and results persist within one transaction and reset at successful transaction boundaries.

### Modified Capabilities

None.

## Impact

- Affects client-side POS sell-page behavior in `Modules/Pos/Resources/views/sell.blade.php`.
- Interacts with existing regular checkout, staged checkout, save-and-new, and draft-load flows without changing their server APIs or persistence models.
- Requires focused verification of modal reopen behavior, successful and failed transaction boundaries, and stale asynchronous search-response handling; no full-suite test run is required for this scoped UI change.
