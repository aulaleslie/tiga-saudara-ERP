## 1. Batch workspace and business context

- [x] 1.1 Replace the existing single-product barcode Livewire composition with a Product-module batch workspace that maintains selected product rows, positive quantities, removal, duplicate-row merging, aggregate label total, and preview state.
- [x] 1.2 Add selected-business state defaulting to `session('setting_id')`, reusing the established business selector/display convention and showing alternate-business selection only to authorized users.
- [x] 1.3 Resolve and authorize the selected business for workspace preview operations using the established document-business resolver, and refresh displayed non-tier prices when the business changes.
- [x] 1.4 Adapt product search/selection for the barcode workspace so it can select printable catalog products and exposes the fields needed for the batch rows without relying on client-supplied barcode or price data.

## 2. Server-side print preparation

- [x] 2.1 Add a protected Product-module POST batch-print route and controller action under the existing barcode-print authorization boundary.
- [x] 2.2 Add request validation for required item collections, unique/valid product IDs, positive integer quantities, the 100-per-product cap, and the 200-label total cap.
- [x] 2.3 Re-resolve selected-business authorization in the endpoint and load all unique selected products with their selected-setting `ProductPrice` rows in a bounded query.
- [x] 2.4 Reject the complete batch with actionable errors for missing products, blank barcodes, unsupported/missing symbology, missing selected-business price rows, and null non-tier `sale_price` values.
- [x] 2.5 Expand validated quantities into ordered individual label records containing escaped product name, SKU/product code, barcode value/symbology, and selected-business non-tier sale price.

## 3. Label rendering and browser print UX

- [x] 3.1 Create a standalone batch-print Blade view that renders product name, SKU, server-generated SVG barcode, barcode value, and `format_currency()` non-tier sale price for every expanded label record.
- [x] 3.2 Render SVG through the installed barcode library using the stored supported symbology, including EAN-13 and Code 128, while allowing only trusted generated SVG to be unescaped.
- [x] 3.3 Add print CSS with matching `@page` and label dimensions of 55 mm × 40 mm, zero page margins, 2 mm safe padding, overflow protection, and a page break between each label only.
- [x] 3.4 Add exactly one load-time `window.print()` invocation and a visible manual Print fallback that prints the entire document without multiplying label copies.
- [x] 3.5 Display concise Blueprint ECO80BT driver setup guidance and the browser/driver limitation notice in the workspace and/or print document.

## 4. Automated verification

- [x] 4.1 Add feature tests for barcode workspace and print-endpoint authorization, including unauthorized and guest access.
- [x] 4.2 Add Livewire tests for multi-product selection, duplicate merging, remove behavior, aggregate totals, business defaulting, and price refresh on allowed business changes.
- [x] 4.3 Add endpoint tests for request shape, invalid quantities, per-product and total caps, product existence, barcode/symbology validation, and selected-business authorization.
- [x] 4.4 Add endpoint/view tests proving selected-business `ProductPrice.sale_price` is used exclusively and missing/null prices reject instead of falling back to global, tier, or zero prices.
- [x] 4.5 Add rendering tests for quantity expansion/order, required label fields, supported EAN-13/Code 128 SVG output, one page per label, one print invocation, fallback button, and 55 mm × 40 mm print CSS.
- [x] 4.6 Run the focused Product module and relevant application tests; record unrelated failures separately.

- [x] 4.7 Apply the deterministic 40-character SKU label display rule (full up to 40 characters; first 39 plus `…` beyond it, at standard label font, server-side and without CSS clipping) and cover it with rendering tests for the 40-, 41-, and 255-character cases.

## 5. Physical printer acceptance

- [ ] 5.1 Perform and document the three-label physical test with the ECO80BT Windows driver and 55 mm × 40 mm gap labels, verifying one dialog and one physical label per HTML page.
- [ ] 5.2 Perform and document the 100-label sequential test with alignment markers and scannable sample barcodes, verifying no skips, blanks, duplicates, clipping, or cumulative drift.
- [ ] 5.3 Repeat physical testing with near-full and near-empty rolls and another compatible roll/batch when available; record driver paper-size, scaling, margin, and gap-media settings used.
