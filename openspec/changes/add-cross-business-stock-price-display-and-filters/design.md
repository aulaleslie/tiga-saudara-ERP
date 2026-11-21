# Design

## Context

See proposal.md for motivation and scope. CrossBusinessStockInventory currently passes live filter properties into its query service on every render and export. Category/brand selections clear search text; there is no product ID selector. The DTO holds search/business/category/brand/availability. Category matching is exact, without descendants. The query service already paginates products and bulk-loads related stock, purchase projections, and unit metadata. Excel uses a generator with dynamic multi-tier business/location headers.

Sales by Product provides the desired selector and applied-filter patterns. Its prior performance defect demonstrated that resetPage invokes updatedPaginators even on page one: avoid duplicate page loads. The installed Livewire version requires compatible lazy/change bindings and the existing single Alpine runtime.

ProductPrice stores one product/business identity with decimal average_purchase_price, sale_price, tier_1_price, and tier_2_price. Existing products.view_prices is separate from products.manage_cross_business_prices; this report needs view access only. Current stock access remains inventory.view_remaining_stock. No migration or new permissions are needed.

## Goals / Non-Goals

**Goals:** Use one canonical applied filter DTO for table, marker lookup, pagination, and exports; bulk-load current base-unit prices without altering stock calculations; keep price data out of unauthorized responses.

**Non-Goals:** Price edits, sales snapshots, historical valuation, conversion-unit prices, tax recalculation, new CSV support, and the separate stock-debt report. Do not refactor other reports or shared business selection beyond necessary compatible reuse.

## Decisions

### Applied criteria and presentation state

Keep draft search/business/category/brand/product/availability separate from validated applied criteria. Normalize and deduplicate positive IDs; intersect businesses with the authorized scope on every data-bearing action. Require at least one business. Initial load retains the existing report with all authorized businesses and default criteria. Apply resets to page one and loads exactly once; Cancel restores applied controls; Reset applies defaults. Presentation state (showPrices, displayMode, expandedBusinesses, exportContent) is independent and does not create pending criteria.

Reuse Sales by Product selector markup/behavior locally, including bounded chip areas above inputs, checkbox rows with scoped Bootstrap 4 alignment, persistent search, caps/overflow feedback, and established bulk-selection limits if those controls are exposed. Product selection is global product identity, not business ownership. Resolve category descendants with the existing cycle-safe resolver; use unique IDs to avoid multiplicative joins. Keep current general search match paths and serial marker eligibility, but drive them from applied search.

Alternative immediate filtering conflicts with the agreed Apply behavior. Copying report lifecycle blindly risks rerunning aggregations during selector typing.

### Authorized page data reuse

Make render consume an already-loaded bounded product page and related aggregate data. Load on initial data request, Apply, or pagination; draft searches, serial dialog interactions, location expansion, and formatting should reuse that page. Use the framework's locked server-produced state or another server-controlled bounded mechanism, with a fingerprint of applied criteria/page/authorized scope and price permission. Revalidate permissions before reuse and invalidate inaccessible data, including prices when permission is revoked. Avoid long-lived cache of stock/prices and unbounded public datasets. Refresh reuses existing loading boundaries; this report is not a historical snapshot.

The price toggle can bulk-fetch prices for the current applied page when enabled; it must not recalculate stock/purchases. Unauthorized users never preload price fields into component state. Disable/hide price controls and reject direct price-bearing requests server-side. Sanitize business IDs again before export and do not silently fall back when the allowed set becomes empty.

### Current prices and missingness

Fetch ProductPrice rows for the page/batch product IDs and authorized applied business IDs in one bulk query. Preserve decimal magnitudes and null/missing distinctions; do not call legacy accessors that default or fall back. Map four fields in fixed order: Harga Modal, Harga Jual, Tier 1, Tier 2. A missing row/null field maps to unavailable, stored zero maps to numeric zero. No sum or grand total of unit prices is meaningful.

Prices remain per base unit even if quantities show conversions. Add a base-unit Satuan identity column when prices are included, retaining stock-only identity layout. In each business group, append four price columns after existing stock/in-delivery columns; expansion changes stock/location columns only. Use Indonesian currency formatting on screen and numeric currency-formatted XLSX cells, preserving underlying decimal values. Do not divide or multiply values by 100 or apply tax recalculation.

### Export content is explicit

Use a validated enum: stock (default), prices, stock_and_prices. It is independent of showPrices: hidden screen prices can still be exported by authorized users. All modes reject pending draft criteria and use canonical applied criteria. Price-only skips stock/purchase aggregate materialization except the stock SQL needed for an applied availability filter. It includes product name/code, category, brand, base Satuan, and four columns per business; no locations or stock quantities. Stock-bearing modes retain current fully expanded location layout, global totals and business in-delivery quantities. Combined mode includes prices once per business.

Centralize dynamic column/header metadata so headings, merges, styles, number formats, row widths, and empty states agree. Preserve strict null-versus-zero serialization using the export package's supported strict-null behavior. Generator source processing bulk-loads/relinquishes related rows per batch; spreadsheet library cell storage still scales with output size and must not be described as constant-memory end-to-end.

## Risks / Trade-offs

- Wide combined tables -> horizontal scrolling and four business price columns only once per business.
- Existing tests assume immediate filters -> update only those assertions to Apply; retain stock, serial, scope, and in-delivery expectations.
- Cached page data may retain revoked access -> reauthorize before reuse, clear invalid scope/price state, and test forged properties/actions.
- Header offsets vary with locations and export mode -> serialized workbook tests for multiple/no-location businesses, missing values, and zeros.
- Current prices may change between screen load and download -> export current values at execution; no immutable snapshot is promised.
- Missing-record display differs from the product price-management page's zero default -> this report deliberately exposes unavailable values as agreed; do not change that other page.

## Migration Plan

Deploy application changes without migrations or normalization commands. Run the focused tests and record query counts for initial load, Apply from another page, selector typing, presentation changes, and a multi-batch export. A human developer performs the small browser/download check; record unavailable checks as pending, never inferred from passing PHP tests. Rollback uses the previous application revision; there are no stored data changes to undo.
