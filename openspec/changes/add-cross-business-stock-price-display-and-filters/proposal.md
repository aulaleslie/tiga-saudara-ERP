# Proposal

## Why

Stok Lintas Bisnis applies filters immediately and its selectors differ from the established Sales by Product experience. Operators also need to compare current modal and selling prices across businesses and export a price list without stock columns.

## What Changes

- Adopt searchable category, brand, and product checkbox multi-selection with chips above inputs, persistent search, individual removal, clear-all, draft/applied state, Apply/Cancel/Reset, applied summary, and pending-change export protection. Include category descendants; use OR within selectors and AND across selectors.
- Preserve general identity/barcode/serial search, authorized business selection (all visible businesses initially), stock availability, quantities, serial dialogs/markers, business/location expansion, and in-delivery calculations.
- Add immediate `Tampilkan Harga`, off by default. Display once per selected business: Harga Modal (`average_purchase_price`), Harga Jual (`sale_price`), Tier 1, and Tier 2 from `product_prices`, per base unit with Satuan shown.
- Require both report access and `products.view_prices` for any price display/download; stock-only access remains available. Missing rows remain unavailable, distinct from saved zero prices, without another-business or legacy fallback.
- Offer independent Excel export choices: Stok saja (default), Harga saja, and Stok + Harga. Each uses applied filters/businesses, including stock availability. Stock-bearing exports retain fully expanded locations; prices never repeat per location.
- Use bounded page/batch loading and avoid report aggregation on draft selector searches or presentation-only changes. Verify with focused automated checks and a small recorded human browser/download smoke check.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-stock-inventory`: Consistent applied filters and product selection, authorized optional business prices, three export contents, and bounded loading.

## Impact

- `app/Livewire/Reports/CrossBusinessStockInventory.php`, its Blade view, filter DTO, query service, and Excel export.
- Reuse Sales by Product selector/filter patterns and category tree resolver where suitable, existing business-scope helpers, ProductPrice, and base-unit metadata.
- Focused cross-business stock feature tests and new filter/price/export regression coverage; existing permissions reused.
- No schema migration, transaction mutation, price editing, historical HPP, conversion-unit pricing, last-purchase pricing, or changes to the separate stock-debt proposal.
