## Context

`Browser.php` renders products for a given `Setting` via a single Eloquent query with `selectSub` calls for tier prices (`display_sale_price`, `display_tier_1_price`, `display_tier_2_price`), then transforms each row in PHP with `resolveContextualPrice()` before handing to a server-rendered Blade view (Tailwind utility classes, no custom CSS file, no JS-driven rendering).

POS's `Cari Produk` modal (`PosProductSearchService::search`) computes stock differently: a raw correlated SQL subquery `SUM(product_stocks.quantity)` scoped to `SalesLocationResolver::resolveLocationIds($settingId)`, then a vanilla-JS renderer (`sell.blade.php`) applies visual treatment using a dedicated Bootstrap-flavored CSS file (`Modules/Pos/Resources/views/sell/css/styles.blade.php`). There is no shared/reusable stock-display component between the two — POS's rendering path is entirely client-side JS operating on a JSON payload, while price-points is entirely server-side Blade. This design ports the *pattern* (query shape + visual language), not code.

## Goals / Non-Goals

**Goals:**
- Compute `available_qty` per product in `Browser.php` using the identical location-scoping mechanism POS uses (`SalesLocationResolver::resolveLocationIds($settingId)` + `SUM(product_stocks.quantity)`), so stock numbers shown on price-points always agree with what POS would show for the same setting.
- Reproduce POS's three-way visual signal (in-stock / out-of-stock-disabled-with-badge / non-stock-managed-service-badge) in price-points' existing Tailwind styling idiom.
- Leave tier-price resolution and search/matching logic completely untouched.

**Non-Goals:**
- Do not import or share POS's CSS file or JS renderer — price-points stays server-rendered Blade/Tailwind.
- Do not change what stock means (no dispatch-aware, reservation-aware, or safety-stock logic) — mirror POS's simple `SUM(quantity)` semantics exactly, including its lack of dispatch-awareness.
- Do not add stock filtering (e.g., hiding out-of-stock products) — POS shows out-of-stock items as visible-but-disabled, and price-points should do the same (visibility, not availability, is the point of a price-point terminal).
- Do not touch `PosProductSearchService`, `PosCartService`, or any POS module file.

## Decisions

**1. Compute `available_qty` via `selectRaw` correlated subquery, matching `PosProductSearchService::availableQtySubquery` exactly.**
Alternative considered: Eloquent relationship/aggregate (`withSum('stocks', 'quantity')`). Rejected because POS deliberately does a raw correlated subquery scoped to a location allowlist, not a plain relationship sum — using a different mechanism risks values silently diverging from POS if `product_stocks` has multi-location rows outside the allowed set. Reusing the identical expression shape keeps the two features numerically guaranteed to agree.

**2. Reuse `App\Support\SalesLocationResolver::resolveLocationIds($settingId)` directly, no wrapper.**
It's already a shared, setting-scoped, non-POS-specific support class (namespace `App\Support`, not `Modules\Pos`), so calling it from `Browser.php` is not a cross-module boundary violation — it's already designed for reuse.

**3. Determine stock-managed / service / OOS status in PHP during `render()`, attach as a computed attribute per row (mirroring how `contextual_price` is already attached), not in Blade.**
Keeps the Blade view a straightforward conditional on precomputed flags, matching the existing `contextual_price` pattern (computed once per-row in `$products->transform()`, not recomputed in the view).

**4. Visual treatment: translate, don't import.**
POS's `.pos-search-card-disabled` (opacity 0.65, `filter: grayscale(0.5)`, disabled cursor, muted colors) and `.pos-search-card-oos-badge` (absolutely positioned, rotated -12deg, red/90 background, white bold uppercase ribbon) get reproduced with Tailwind utility classes on the existing card `<div>` (`opacity-65 grayscale cursor-not-allowed bg-slate-100 border-slate-200`, plus an absolutely positioned rotated badge `<span>`). The non-stock-managed "Service" badge reuses the same ribbon shape with a blue background instead of red. This keeps price-points' styling self-contained (no dependency on a POS module CSS file loading on a non-POS route) while producing the same visual signal.

**5. Stock line copy matches POS text exactly: `Stok: {n}` for stock-managed items, `-` for non-stock-managed, `Stok Kosong` badge text for zero-stock.**
Consistency of wording avoids retraining staff who use both screens.

**6. Denominate the Stok quantity using the Product list's `formatQuantityValue` logic, not POS's bare number.**
POS's own search results show a bare number for stock (`Stok: 12`) — there is nothing to port from POS here. The Product list page (`Modules\Product\DataTables\ProductDataTable::formatQuantityValue`, lines 159-173) already solves "show a stock quantity in product units" for the same `product_stocks`-derived quantity concept, using `conversions->sortByDesc('conversion_factor')->first()` to pick the single largest conversion and expressing the quantity as `"{floor(qty / factor)} {biggestUnit} {qty % factor} {baseUnit}"`, falling back to `"{qty} {baseUnit}"` with no conversions, and a bare number with no base unit at all. Reusing this exact algorithm (rather than inventing a different one, e.g. a full multi-level chain) keeps price-points' stock text visually and behaviorally consistent with the one other place in the app that already denominates quantities — including reproducing its known limitation of only considering the single largest conversion, not a full unit hierarchy.

Alternative considered: extract `formatQuantityValue` into a shared helper (e.g. `App\Support\QuantityFormatter`) callable from both `ProductDataTable` and `Browser`. Rejected for this change as scope creep — `Browser.php`'s formatting need is small enough to duplicate the ~10-line algorithm locally; a shared extraction can be a follow-up if a third caller emerges.

**Data dependency**: `Browser.php`'s product query does not currently eager-load `baseUnit`. It must add `'baseUnit:id,short_name'` to the existing `with([...])` array (`conversions` and `conversions.unit` are already eager-loaded, so no new query is needed for the conversion side).

## Risks / Trade-offs

- [Risk] `product_stocks` aggregation with no location match (empty `allowedLocationIds`) → subquery returns `NULL`, must `COALESCE` to 0, same as POS — mitigated by copying the exact `COALESCE((...), 0)` wrapper POS uses.
- [Risk] Adding a correlated subquery per row to an already-paginated query could add query cost — mitigated by scope: same pattern POS already runs per-request at higher result volumes (up to 20 rows); price-points paginates at 12/page, so cost is bounded similarly.
- [Trade-off] Not sharing a component between POS and price-points means future stock-display changes must be manually kept in sync in two places. Accepted because the two features have different rendering models (JS vs Blade) and unifying them is out of scope for this change.
- [Risk] Duplicating `formatQuantityValue`'s algorithm instead of extracting a shared helper means a future bug fix or behavior change to the Product list's denomination logic will not automatically propagate to price-points. Mitigated by keeping the duplicated logic small and by this design doc's decision rationale making the duplication intentional and easy to find later.
- [Risk] `floor()`/`%` on `available_qty` require integer semantics; `available_qty` is currently surfaced as a float from the `SUM()` subquery. Mitigated by casting to `(int)` before applying floor/modulo, consistent with how `ProductDataTable::formatQuantityValue` operates on integer quantities.

## Open Questions

None outstanding — visual treatment approach (Tailwind translation vs CSS import), tier-price scope (unchanged), and unit-denomination approach (reuse Product list's single-largest-conversion algorithm, reformatting the Stok line rather than adding a separate unit label) were all confirmed with the user during exploration.
