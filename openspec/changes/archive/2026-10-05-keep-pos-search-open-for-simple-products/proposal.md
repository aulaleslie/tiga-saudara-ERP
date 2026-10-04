# Proposal

## Why

Cashiers who are adding several items from one keyword search must reopen `Cari Produk` after every selection. Keeping the results visible for straightforward products makes repeated selection faster while retaining the existing search state for the current transaction.

## What Changes

- Keep the `Cari Produk` modal open after selecting a result that needs neither unit nor bundle selection, including repeated selections of the same card.
- Continue using the existing cart add behavior: a matching line increases in quantity; a different product or cart identity creates its appropriate line.
- Preserve the current modal transition for products requiring unit or bundle selection: close product search and proceed through the existing selection flow.
- Preserve keyword and rendered results across product additions and modal reopening, with the existing successful transaction-boundary reset.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-product-search-lifecycle`: Define when selecting a search result keeps the modal open and when the existing unit or bundle flow closes it.

## Impact

- POS sell-page product-result click handling in `Modules/Pos/Resources/views/sell.blade.php`.
- No new endpoint, database change, or dependency is expected.
- Focused automated verification of the result-card behavior; a human developer will perform browser testing.
