## 1. Unit metadata and server validation

- [x] 1.1 Add shared business-scoped POS unit-options resolution with base-unit labels, enabled conversion IDs/labels/factors, and batch loading for search results; preserve missing-row enablement defaults.
- [x] 1.2 Enrich product search and base-barcode scan responses with unit options while retaining distinct conversion-barcode and serial provenance.
- [x] 1.3 Validate finite integer conversion factors greater than 1 before casting or multiplying in the cart add path; retain ownership and current-business sales checks and return actionable errors without mutation.

## 2. Unit picker and selection lifecycle

- [x] 2.1 Add and include a unit-selection modal using existing bundle cards and modal conventions, showing base unit and conversion factors with invalid options unavailable and explained.
- [x] 2.2 Route every eligible name-result/base-barcode add through a fresh unit selection before bundle intent; bypass unit choice for conversion barcodes and serial scans.
- [x] 2.3 Implement operation-owned pending state, safe modal transitions, stale-response rejection, cancellation cleanup, focus restoration, and guards against duplicate clicks, Enter activation, and scanner re-entry during selection/submission.

## 3. Bundle and quantity integration

- [x] 3.1 Carry qty 1 and selected conversion_id into both bundle additions and explicit no-bundle continuation; preserve existing bundle intent and merge rules.
- [x] 3.2 Show selected unit and resulting bundle count in bundle selection with the authoritative per-bundle price clearly labeled.
- [x] 3.3 Ensure accepted new serial scans always contribute one base unit without inherited conversion state, preserving bundle intent, serial uniqueness, and row targeting; keep manual quantity and plus/minus controls in base units.

## 4. Focused automated verification

- [x] 4.1 Add focused search/scan tests for eligible units, no/all-disabled conversions, business isolation, purchase-flag independence, missing-price defaults, and conversion-barcode/serial routing metadata.
- [x] 4.2 Add focused cart tests for base addition, factor addition and repeat merge, stale sales disablement, foreign conversion IDs, invalid/fractional factors, and no mutation on rejection.
- [x] 4.3 Extend targeted bundle/cart tests for BOX factor 12, explicit no-bundle continuation, existing pricing/merge behavior, base-unit quantity edits, and serial additions of one.
- [x] 4.4 Extend focused checkout stock/serial fixtures to verify 12 bundles with child quantity 2 require 24 child units, enforce multiplied serial requirements, and avoid duplicate deductions.
- [x] 4.5 Run only the affected test files or focused php artisan test filters and relevant syntax checks; record commands and outcomes. Do not run the full suite or automated browser tests.

## 5. Human browser handoff

- [x] 5.1 Prepare a human-only browser checklist covering repeated name/base-barcode selection, PCS/BOX choice, unit-before-bundle ordering, 12-bundle preview, no-bundle continuation, conversion-barcode bypass, serial increment one, and base-unit cart controls.
- [x] 5.2 Include human checks for cancelling either modal, fresh state after cancellation, double-click/repeated Enter, scanner input while a modal is open, stale responses, focus restoration, disabled conversion errors, and invalid-factor messaging. Hand off the checklist and clearly report browser execution as pending until a human supplies results.
