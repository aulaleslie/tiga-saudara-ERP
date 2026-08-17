## Why

The `/price-points` browser (`App\Livewire\PricePoint\Browser`) shows product name, codes, tier-aware price, and unit conversions, but never shows stock — a customer-facing/sales-facing terminal can promise a price on a product that has zero available quantity. POS's product search (`Modules/Pos/Services/PosProductSearchService`) already solves an equivalent problem: it computes location-scoped `available_qty` and visually distinguishes in-stock, out-of-stock, and non-stock-managed ("Service") products. Price Points should adopt the same stock lookup and the same visual treatment so staff browsing it see accurate, POS-consistent stock signals without duplicating a separate stock-computation approach.

## What Changes

- Add a location-scoped `available_qty` computation to `Browser.php`'s product query, using the same pattern as `PosProductSearchService::availableQtyExpression` (`SUM(product_stocks.quantity)` filtered to `SalesLocationResolver::resolveLocationIds($settingId)`), correlated to `products.id` instead of a raw-SQL alias.
- Add a stock line to each product card in `browser.blade.php`, showing the numeric `available_qty`, or `-` for non-stock-managed (`stock_managed = false`) products.
- Add the same three-way visual treatment POS uses in its search results:
  - Out of stock (`stock_managed = true` and `available_qty <= 0`): card rendered in a visually disabled/greyed state with an "Stok Kosong" ribbon badge, and stock text shown in red/bold.
  - Non-stock-managed / service product: "Service" badge (POS's blue treatment), stock shown as `-`, never treated as disabled.
  - In stock: normal card styling, numeric stock shown.
  - Reproduce POS's visual language (opacity/greyscale for disabled state, diagonal ribbon badge, red/bold OOS text) using Tailwind utility classes consistent with price-points' existing styling, rather than importing POS's Bootstrap-flavored CSS file directly.
- Explicitly preserve the existing tier-price resolution (`Browser::resolveContextualPrice`) and the existing product search/pagination query untouched — this change only adds stock computation and display, it does not change what price is shown or how products are matched/searched.

## Capabilities

### New Capabilities
- `price-point-stock-visibility`: Price Points browser displays POS-equivalent, location-scoped stock information (available quantity, out-of-stock indicator, non-stock-managed indicator) per product card, without altering existing tier-price or search logic.

### Modified Capabilities
- (none — no existing spec covers `/price-points`; tier pricing and search behavior are unchanged)

## Impact

- `app/Livewire/PricePoint/Browser.php`: add `available_qty` computation to the product query (new `selectRaw`/subquery using `SalesLocationResolver`).
- `resources/views/livewire/price-point/browser.blade.php`: add stock line + badge/disabled-state markup per card.
- Dependency: `App\Support\SalesLocationResolver::resolveLocationIds($settingId)` (already used by POS, reused as-is, no changes).
- No changes to `PosProductSearchService`, `PosCartService`, or any POS module code — POS is a reference pattern only, not a shared dependency being modified.
- No database schema changes; reads existing `product_stocks` table.
