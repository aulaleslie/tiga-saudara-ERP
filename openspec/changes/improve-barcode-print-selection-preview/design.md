## Context

The Product module's browser batch barcode workflow already resolves an authorized selected business, loads its non-tier `product_prices.sale_price`, validates primary barcode data and symbology, generates SVG, and prints product name, SKU, barcode value, and price. Those complete label fields appear only after the operator explicitly requests the expanded batch preview or opens the print document.

`BarcodeProductSearch` currently returns only product ID, name, code, and unit. It can find partial primary barcodes through `Product::globalSearch()` and select an exact active primary-barcode match on Enter, but its view does not display barcode or price and the component has no selected-business context. `BarcodeBatchWorkspace` similarly stores only product identity and quantity in `rows`; changing a quantity or business clears the expanded preview.

The change must preserve the `barcodes.print` permission boundary, `EffectiveDocumentBusinessResolver` authorization, primary-barcode-only behavior, selected-business non-tier price semantics, existing batch limits, and final endpoint revalidation. No database or printer integration change is required.

## Goals / Non-Goals

**Goals:**

- Make primary barcode and authorized selected-business price visible while searching.
- Show one faithful compact label preview per selected product immediately during batch composition.
- Keep suggestion and row-preview prices synchronized with business selection.
- Reuse the established label payload and renderer semantics so UI previews do not disagree with printed output.
- Surface missing/invalid label data before the operator reaches the expanded preview or print action.
- Verify the behavior through focused Product-module tests only.

**Non-Goals:**

- Removing or redesigning the existing expanded batch preview.
- Supporting product-unit-conversion barcode search or label printing.
- Changing physical label dimensions, print CSS, printer guidance, quantities, or batch limits.
- Changing price selection to tiers, global values, zero fallback, or product master price.
- Trusting browser-carried barcode, price, symbology, or SVG data during final printing.
- Adding schema, packages, device APIs, or direct printer communication.

## Decisions

### Treat the workspace business as explicit reactive search context

The parent workspace will pass `selectedSettingId` to the barcode product-search component as reactive input. Search refreshes will resolve that setting through `EffectiveDocumentBusinessResolver` before loading a constrained matching `prices` relation. The suggestion payload/view can then display the primary barcode and the resolved price row, or an explicit unavailable state.

This is preferred over reading `session('setting_id')` independently in the child because an authorized operator can override the business without changing the session. It is also preferred over accepting a price in the client selection payload as authoritative because that value becomes stale and could expose or preserve the wrong business context.

If business authorization fails, price data for the requested setting is not queried or rendered. The workspace/search surface actionable feedback through component state rather than silently falling back to another setting.

### Build selected-row presentation from a bounded server-resolved preview map

The workspace will keep its canonical mutable selection minimal: product IDs and quantities remain the inputs to printing. A derived preview map keyed by product ID will be built server-side for the unique selected IDs and resolved business, using a bounded eager load rather than querying from each Blade row.

The map will contain either the established label payload or an actionable product-specific error. The view renders one rightmost compact preview per selected product, irrespective of quantity. This avoids duplicating potentially hundreds of SVGs and prevents preview-only display values from becoming print inputs.

Persisting barcode and price permanently inside each row was rejected because they would need complex invalidation after business or catalog changes and could accidentally become trusted submission data. Generating product and price queries inside Blade was rejected because it creates N+1 behavior and weakens authorization boundaries.

### Reuse the barcode batch service as the label-semantics authority

Compact previews will be produced through the same `BarcodeBatchService` label-building behavior used by the expanded preview and final print document: selected-business price resolution, barcode presence, explicit EAN-13 validation, symbology normalization/fallback, SVG generation, and deterministic SKU display.

The service may expose or internally reuse a bounded method that returns one preview result per unique selected product without expanding by quantity. Invalid results must retain product-specific error information so the workspace can display it inline. The existing `expand()` contract and final endpoint behavior remain unchanged.

Reimplementing SVG generation or validation in JavaScript/Blade was rejected because it could show a label that the print endpoint rejects or renders differently.

### Refresh derived presentation at every relevant state boundary

Adding/removing a product, changing business, and rendering after catalog-backed interaction will rebuild or invalidate the preview map. Quantity changes update totals and invalidate the expanded batch preview but do not require duplicating or semantically changing the per-product preview. Search results refresh against the current business when the business context changes; stale results must not display the old business price.

The existing expanded preview remains opt-in and quantity-expanded. The compact preview answers product correctness; the expanded preview continues to expose exact batch order and copies.

### Keep scan and search identity rules unchanged

Partial suggestions continue to use `Product::globalSearch()`, which already searches active products by primary barcode along with name, SKU, category, and brand. Exact Enter lookup remains an active-product equality match on `products.barcode`. Product-unit-conversion barcodes remain excluded.

Suggestion presentation will add primary barcode and price, and the input placeholder/help text will explicitly mention barcode scanning/search. Selection events should continue to identify the product by ID; extra presentation fields are not authoritative.

### Use focused Product-module verification

Automated verification will target `Modules/Product/Tests/Feature/BrowserBatchBarcodePrintingTest.php`, with another directly related Product-module test only if separation materially improves coverage. Tests will cover authorized business pricing, unavailable prices, barcode display, compact SVG preview, business refresh, error states, exact scanner behavior, and conversion-barcode exclusion. The full application suite will not be run for this change.

## Risks / Trade-offs

- [Generating SVG previews for many selected products increases Livewire payload and render cost] → Generate exactly one preview per unique product, retain the existing 200-label batch bound, use one bounded product/price load, and avoid quantity-expanded compact previews.
- [A business change briefly leaves stale prices visible] → Invalidate current search results and row-preview data as part of the business-change handler before rebuilding them for the newly resolved setting.
- [Search suggestions expose business-specific pricing] → Resolve business access before querying constrained price rows and never include prices from other settings or fallback sources.
- [Catalog data changes after the compact preview] → Keep the compact preview informational and preserve final endpoint reload/revalidation as the print authority.
- [Inline errors and existing batch-level errors duplicate feedback] → Use inline errors for early product-specific guidance while retaining batch-level errors as the authoritative gate and summary.
- [The wider table becomes cramped on smaller screens] → Keep the existing responsive table wrapper and use a compact fixed/minimum preview layout that can scroll horizontally without shrinking the barcode below a useful visual size.

## Migration Plan

Deploy the additive Livewire, Blade, service, and focused-test changes without a database migration. Existing URLs, permissions, request payloads, and print output remain compatible. After deployment, verify name/SKU/barcode search, scanner Enter, business switching, compact previews, invalid-data feedback, and a representative print document using the focused Product-module test command.

Rollback is an application-code rollback. No data conversion or cleanup is required.

## Open Questions

None. The compact preview represents one product label and is intentionally not repeated by quantity; the existing expanded preview remains available for exact copy/order inspection.
