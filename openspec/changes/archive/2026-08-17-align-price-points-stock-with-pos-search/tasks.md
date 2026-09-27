## 1. Stock computation in Browser.php

- [x] 1.1 In `App\Livewire\PricePoint\Browser::render()`, resolve `$allowedLocationIds` via `App\Support\SalesLocationResolver::resolveLocationIds($settingId)`, filtered/mapped to positive ints (mirroring `PosProductSearchService::search` lines 19-23).
- [x] 1.2 Add a `selectRaw` (or equivalent `selectSub`) to the product query computing `available_qty` as `COALESCE((SELECT SUM(ps.quantity) FROM product_stocks ps WHERE ps.product_id = products.id AND ps.location_id IN (<allowed ids>)), 0)`, matching `PosProductSearchService::availableQtyExpression`/`availableQtySubquery`.
- [x] 1.3 Ensure `stock_managed` is available on each row (already included via `select('products.*')`) — confirm no explicit column list is dropping it.
- [x] 1.4 Handle the empty-`$allowedLocationIds` case (e.g., no sales locations configured for setting) — subquery must still safely resolve to 0 rather than producing invalid SQL (mirror POS's early-return/empty-payload behavior, adapted to not short-circuit the whole listing).

## 2. Computed stock-state attribute

- [x] 2.1 In the existing `$products->transform()` block (alongside `contextual_price`), compute a `stock_state` attribute per product: `'service'` when `!stock_managed`, `'out_of_stock'` when `stock_managed && available_qty <= 0`, else `'in_stock'`.
- [x] 2.2 Confirm this computation does not alter `resolveContextualPrice()` or any existing transform logic — additive only.

## 3. Blade view — stock display

- [x] 3.1 Add a "Stok" info block to each product card in `browser.blade.php`, alongside the existing "Harga" / "Kode / Barcode" grid cells, showing the numeric `available_qty` for `in_stock`/`out_of_stock` states and `-` for `service` state.
- [x] 3.2 Apply red/bold text styling to the stock line specifically when `stock_state === 'out_of_stock'` (matching POS's `text-danger font-weight-bold` treatment, translated to Tailwind e.g. `text-red-600 font-bold`).

## 4. Blade view — card visual states

- [x] 4.1 Add conditional Tailwind classes to the card container for `out_of_stock` state: reduced opacity, greyscale filter, muted background/border, `cursor-not-allowed` (translating POS's `.pos-search-card-disabled` rule block).
- [x] 4.2 Add a ribbon-style badge element (absolutely positioned, rotated, rounded, bold uppercase) reading "Stok Kosong" shown only when `stock_state === 'out_of_stock'`, styled with a red/90 background per POS's `.pos-search-card-oos-badge`.
- [x] 4.3 Add the same ribbon badge shown when `stock_state === 'service'`, reading "Service", styled with a blue background (POS's `var(--info)` treatment) instead of red — and never combined with the disabled/greyscale card treatment.
- [x] 4.4 Ensure the card's `position: relative` (or Tailwind `relative`) is present so the absolutely positioned badge anchors correctly.

## 5. Verification

- [x] 5.1 Manually load `/price-points` for a setting with a mix of in-stock, zero-stock, and non-stock-managed (`stock_managed = false`) products; confirm all three visual states render as specified.
- [x] 5.2 Confirm a product's displayed `available_qty` matches what POS's `Cari Produk` search shows for the same product/setting (cross-check against `PosProductSearchService` output).
- [x] 5.3 Confirm selecting a `WHOLESALER`/`RESELLER` customer still resolves tier prices exactly as before (no regression from the added stock query/joins).
- [x] 5.4 Confirm search, pagination, and empty-results states are unaffected by the added stock computation.
- [x] 5.5 Run relevant PHP tests (`composer test:fresh-sqlite` or focused filter) if any automated coverage exists/is added for `PricePoint\Browser`.

## 6. Unit-denominated stock quantity display

- [x] 6.1 Add `'baseUnit:id,short_name'` to `Browser.php`'s product query `with([...])` array.
- [x] 6.2 In the `$products->transform()` block, compute a `formatted_available_qty` string per product mirroring `ProductDataTable::formatQuantityValue`: if `baseUnit` exists and `conversions` is non-empty, pick `conversions->sortByDesc('conversion_factor')->first()` and render `"{floor((int)available_qty / factor)} {biggestUnit->short_name} {(int)available_qty % factor} {baseUnit->short_name}"`; else if `baseUnit` exists, render `"{(int)available_qty} {baseUnit->short_name}"`; else render `"{(int)available_qty}"`.
- [x] 6.3 For `stock_state === 'service'`, `formatted_available_qty` SHALL NOT be used — the Stok field continues to show `-` unchanged.
- [x] 6.4 Update `browser.blade.php`'s Stok block to render `formatted_available_qty` instead of the bare `{{ (float) ($product->available_qty ?? 0) }}`, for both the `in_stock` and `out_of_stock` branches (out-of-stock keeps its red/bold styling, now wrapping the formatted string).
- [x] 6.5 Add/extend a feature test asserting: (a) a product with a base unit and one conversion renders the `"{N} {unit} {remainder} {baseUnit}"` format; (b) a product with multiple conversions uses only the largest factor; (c) a product with a base unit and no conversions renders `"{qty} {baseUnit}"`; (d) a product with no base unit renders a bare number; (e) a service product still renders `-`.
- [x] 6.6 Run `composer test:fresh-sqlite` (or focused filter on `PricePointBrowserSearchTest`) to confirm no regressions in existing stock-state/tier-price tests.
