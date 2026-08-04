## Context

The existing Product-module Print Barcode page is available under `barcodes.print`, but its shared product search selects one stock-managed product and the Livewire component generates Code 128 previews before offering a PDF download. It cannot form a multi-product browser print job or resolve pricing from an operator-selected business.

The application already has the required foundations: Laravel 10, Livewire 3, the `Product`/`ProductPrice` models, the server-side `milon/barcode` SVG library, `session('setting_id')`, `BusinessSelector`, and `EffectiveDocumentBusinessResolver`. `product_prices` has one row per product and business (`setting_id`), with the requested non-tier price in `sale_price`.

The cashier PC must use the installed Blueprint ECO80BT Windows driver through ordinary browser printing. The target media is 55 mm × 40 mm die-cut gap labels. No print bridge, browser extension, desktop application, browser device API, or direct TSPL/CPCL transmission is permitted.

## Goals / Non-Goals

**Goals:**

- Let an authorized operator create a batch from multiple products and quantities, inspect its complete preview, and print it in one browser-initiated job.
- Produce one physical-label-sized HTML page for every expanded label, carrying product name, SKU (`product_code`), SVG barcode, barcode value, and selected-business non-tier sale price.
- Default the selected business to the active session setting and apply the existing cross-business authorization model when it changes.
- Prevent inaccurate labels through server-side revalidation of products, barcode data, supported symbology, quantity limits, and `product_prices.sale_price`.

**Non-Goals:**

- Silent printing, automatic printer selection/configuration, printer-status reporting, gap calibration, skipped-label detection, or confirmation that a physical label printed.
- QZ Tray, browser extensions, native applications, Web Bluetooth, raw USB, or direct TSPL/CPCL printing.
- Barcode assignment, barcode-data mutation, tier-price selection, price-history/audit records, PDF generation, or label printing for product-unit conversions.
- Compensating in Laravel or CSS for a printer driver/media sensor that cannot reliably detect the physical label gap.

## Decisions

### Use a Product-module batch Livewire workspace and a dedicated POST print endpoint

The existing `barcode.print` page and permission remain the entry point. Replace its single-product composition with a batch-focused Livewire component that maintains selected product IDs and positive integer quantities, a selected setting ID, live batch total, and preview state. Product additions are de-duplicated by product ID; selecting an existing product increments or updates its single row rather than creating duplicate rows.

The print action submits `{ setting_id, items: [{ product_id, quantity }] }` to a dedicated Product-module POST endpoint. The endpoint is the sole authority for the printable document: it validates the request, resolves business access, performs the bounded product/price lookup, validates every result, expands quantities, and renders the standalone Blade print view.

Keeping rendering inside Livewire was rejected because browser navigation/response handling is awkward for a standalone print document and it risks treating UI state as authoritative. One endpoint avoids per-label requests, frames, tabs, popups, and print calls.

### Resolve selected business through the existing authorization service

The workspace initializes `selectedSettingId` from `session('setting_id')`. It shows the existing `BusinessSelector` only to Super Admins or users with `documents.business.override`; other users print for the active business. Both preview data and the print endpoint resolve the setting with `EffectiveDocumentBusinessResolver`.

Trusting an arbitrary setting ID from the browser was rejected because it could expose another business's prices. Mutating the session setting as part of printing was rejected because label-print context must not unexpectedly switch the user’s active ERP business.

### Read base barcode data and non-tier price in one bounded lookup

For the unique requested product IDs, query products once with their matching `ProductPrice` row constrained to the resolved setting ID. The label price is exactly that row's `sale_price`; neither `tier_1_price` nor `tier_2_price`, a global product price, nor a fallback price may be substituted. A missing row or null `sale_price` invalidates the batch and names the affected product/SKU.

This differs intentionally from some sales UI behavior that can use a zero-price fallback. A shelf label with an incorrect price is materially worse than a rejected print batch.

### Render barcode SVG according to stored supported symbology

Generate SVG server-side using the already installed `milon/barcode` library. Use the product's configured symbology when it is supported by the renderer (including EAN-13 and Code 128 values used by current product data). A blank barcode or unknown/unsupported symbology rejects the batch with an actionable product-specific error. Product text is escaped normally; only library-produced SVG is emitted unescaped.

Hard-coding Code 128 was rejected because the recently normalized EAN-13 catalog records carry `product_barcode_symbology = EAN13`. Adding `picqer/php-barcode-generator` was rejected because the project already ships a server-side SVG barcode library.

### Expand labels server-side and print once using physical page dimensions

Expand each validated item into one immutable render record per requested copy. The standalone view renders one `.label-page` element per record and uses `@page { size: 55mm 40mm; margin: 0; }`, a matching `55mm × 40mm` element, 2 mm internal padding, and forced page breaks except after the final label. The page uses one load-time `window.print()` call and offers a visible Print fallback button.

The label keeps critical content inside the approximately 51 mm × 36 mm safe area. Product names wrap/truncate within their allocated area; the barcode, value, and price cannot be allowed to overflow or clip. Browser copies remain `1`, because copies would duplicate the entire expanded batch.

### Apply a fixed 40-character SKU display rule

`products.product_code` is validated at `max:255`, which cannot fit legibly on 55 mm media. The label prints a `product_code` of 40 characters or fewer in full, and prints longer values as their first 39 characters plus the Unicode ellipsis `…`. The rule is applied server-side in `BarcodeBatchService::displaySku()` so the printed result is deterministic and testable.

Adaptive font shrinking was rejected: fitting 255 characters required roughly 4pt, which is present on paper but not human-readable, and it made the printed output depend on the renderer's text metrics. CSS `text-overflow: ellipsis` with `overflow: hidden` was rejected because the cut-off point then varies with font metrics and print scaling, and a clipped value can look complete to an operator. The visible `…` marks the truncation explicitly.

The SKU keeps the standard label font size in every case. Truncation is a print-layout concern only: the stored `product_code` is never mutated, the workspace table and preview show the full value, and the barcode encodes the untruncated data, so a scan always recovers the real identifier.

Adding the physical gap to CSS height was rejected: the media sensor, not HTML, positions the next die-cut label unless physical driver testing proves a full pitch is necessary.

### Apply explicit batch safeguards

Enforce positive integer quantities, at most 100 labels per product, and at most 200 labels in the complete batch. The UI shows both per-row quantities and the aggregate count before preview/print; endpoint validation repeats every bound. These limits contain rendering latency and accidental large jobs while preserving the current screen's per-product maximum.

## Risks / Trade-offs

- [Windows driver paper size, scale, margins, or media mode disagrees with the label stock] → Display cashier setup guidance and require physical acceptance tests with 55 mm × 40 mm gap media, actual size/100% scaling, no margins, one page per sheet, one copy, headers/footers off, and duplex off.
- [Gap sensor, calibration, roll tension, or media variability causes skipped labels or drift] → Treat calibration/media correction as operational work; run three-label, 100-label sequential, and roll-variation tests. Application code cannot fix failed gap detection.
- [Product data changes after preview] → Re-query at the endpoint; the printed document reflects the product, barcode, symbology, and selected-business non-tier price at print-document generation time.
- [A long product name competes with barcode scanability] → Constrain name layout and test representative long names; preserve barcode dimensions and quiet area over product-name completeness.
- [A user attempts a different business] → Resolve the selected setting server-side using the established access resolver before querying price rows or rendering output.

## Migration Plan

Deploy the additive route/controller/view/component changes with no schema migration. The existing Print Barcode menu URL and `barcodes.print` permission stay in place. Existing users move from the single-product PDF workflow to browser batch printing; no stored barcode or price data is altered.

Rollback is an application-release rollback. No data migration is required. Cashier rollout must include driver paper-size configuration and successful physical print acceptance tests before relying on the workflow operationally.

## Open Questions

None. The initial limits are 100 labels per SKU and 200 labels per batch; they can be made configurable in a later change if operational usage requires it.
