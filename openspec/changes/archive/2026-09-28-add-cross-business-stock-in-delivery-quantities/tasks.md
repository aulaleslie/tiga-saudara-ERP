# Tasks

## 1. Quantity projection and presentation foundations

- [x] 1.1 Add focused test fixtures for approved, partially received, fully/ineligibly received, archived, over-received, unapproved-receipt, multi-business, and fractional purchase details; verify the fixtures exercise detail-level clamping and selected-business scope.
- [x] 1.2 Implement a bulk in-delivery aggregate keyed by product and purchase business for requested product/business IDs, preserving decimal quantities and subtracting only approved receiving notes; verify focused service tests cover every status, archive, receipt, and clamp rule.
- [x] 1.3 Add a decimal-safe quantity presenter supporting `Hanya Angka` and largest-conversion-plus-base-remainder `Dengan Satuan` modes; verify focused unit tests cover integers without `,00`, two-place comma decimals, rounding, fractional remainders, largest conversion, and missing unit/conversion fallbacks.

## 2. Cross-business report data and performance

- [x] 2.1 Extend paginated report loading to bulk-fetch in-delivery aggregates and the minimal base-unit/conversion metadata for current-page products, resolve the largest valid conversion once per product, and expose canonical global/business quantities without location allocation; verify focused query-service tests cover totals and row shape.
- [x] 2.2 Inspect existing purchase/receiving indexes and representative query plans, add an additive index migration only when evidence shows a missing access path, and record the inspected plan or focused schema assertion.
- [x] 2.3 Add a focused query-count regression test proving report rendering introduces no per-product, per-business, per-location, per-purchase-detail, or per-cell query growth as fixture size increases.
- [x] 2.4 Cache or retain the canonical current-page aggregate payload independently of display mode and verify a mode-only change reformats rows without issuing stock or purchase aggregation queries solely for formatting.

## 3. Interactive report layout

- [x] 3.1 Add and validate the report-wide `Hanya Angka` / `Dengan Satuan` selector, default it to decimal, and verify focused Livewire tests show that it does not alter filters, pagination, authorization scope, or canonical quantities.
- [x] 3.2 Add the global and per-business `Dalam Pengiriman` columns, retain each business value once when locations expand, and apply the selected presenter to every global, business, and location quantity cell; verify focused render tests cover collapsed and expanded layouts in both modes.
- [x] 3.3 Preserve actual-stock-only availability semantics and existing serial/tax tooltip behavior while adding the projection and formatting changes; verify focused feature tests cover a zero-stock product with positive incoming quantity and representative existing Good/Bad interactions.

## 4. Bounded Excel export

- [x] 4.1 Refactor export traversal to stable bounded batches that bulk-load stock, in-delivery, and unit metadata per batch; verify a focused multi-batch export test contains every filtered product in stable order without all related records being retained at once.
- [x] 4.2 Extend export headers with one global and one per-business `Dalam Pengiriman` column while keeping location sections limited to Good/Bad; verify focused export tests cover selected-business scope and fully expanded location layout.
- [x] 4.3 Apply numeric spreadsheet cells and an integer-or-two-decimal display format in decimal mode, and matching unit-denominated text in conversion mode, to every existing and new quantity column; verify focused workbook assertions cover whole, fractional, rounded, and converted values.

## 5. Focused verification and handoff

- [x] 5.1 Run only the focused quantity-presenter, query-service, Livewire/report feature, performance, and export tests for this change and record the commands/results; do not run or plan a full-suite test pass.
- [x] 5.2 Perform a final diff review for unrelated changes, confirm no purchase/stock mutation or per-location in-delivery attribution was introduced, and provide a concise manual browser checklist for selector switching, business expansion, filtering, and export inspection.
