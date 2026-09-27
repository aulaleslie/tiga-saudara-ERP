## 1. Daizu Classification and Owner Resolution

- [x] 1.1 Add sales import helpers to normalize product names, detect whole-word `KEDELE`, `KEDELAI`, or `RAGI`, and resolve the Daizu Kedelai setting.
- [x] 1.2 Update sales owner resolution so Daizu-matched products resolve to Daizu before Tag or product marker rules.
- [x] 1.3 Update stock owner resolution so Daizu-matched products resolve to Daizu before marker and purchase-history fallback.
- [x] 1.4 Preserve existing Tag, marker, and history fallback behavior for non-Daizu products.

## 2. Grouping, Duplicate Handling, and Setup Failures

- [x] 2.1 Update invoice grouping so Daizu-matched rows group by effective Daizu ownership instead of raw Tag or marker.
- [x] 2.2 Update Daizu duplicate detection to skip existing Daizu-owned imported invoices.
- [x] 2.3 Add legacy duplicate conflict detection for existing non-Daizu sales with the same imported invoice reference and Daizu-matched products.
- [x] 2.4 Mark Daizu-matched rows invalid with clear errors when the Daizu setting is missing.
- [x] 2.5 Ensure skipped duplicate rows remain counted as processed rows without incrementing successful import count.

## 3. Location, Dispatch, Stock, and Price Alignment

- [x] 3.1 Add Daizu location resolution that matches CSV `gudang` within Daizu locations when provided.
- [x] 3.2 Use a default Daizu location when CSV `gudang` is blank.
- [x] 3.3 Mark Daizu rows invalid when the requested or default Daizu location cannot be found, without falling back to another setting location.
- [x] 3.4 Ensure Daizu sales create or update ProductPrice rows under Daizu Kedelai.
- [x] 3.5 Ensure Daizu dispatch details, product stock decrements, and inventory Transactions use the same Daizu location and Daizu `setting_id`.

## 4. Tests and Verification

- [x] 4.1 Add tests for Daizu sale owner override with blank Tag, non-Daizu Tag, and product markers.
- [x] 4.2 Add tests for whole-word detection, including non-matches such as `PREKEDELAI` and `RAGING`.
- [x] 4.3 Add tests that sale `setting_id`, ProductPrice `setting_id`, dispatch location, product stock decrement, and Transaction `setting_id` stay aligned for Daizu products.
- [x] 4.4 Add tests for Daizu `gudang` location selection, blank `gudang` default location, and missing location failure.
- [x] 4.5 Add tests for existing Daizu duplicate skip and existing non-Daizu Daizu-product legacy conflict.
- [x] 4.6 Run focused sales import and import stock location tests.
