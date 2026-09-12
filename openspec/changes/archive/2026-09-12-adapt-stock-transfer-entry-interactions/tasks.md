## 1. Characterize Existing Contracts

- [x] 1.1 Add focused create/edit characterization tests for transfer entry mounting, row hydration, parent-facing row updates, origin/condition remounts, and destination-only preservation.
- [x] 1.2 Add focused characterization tests for the stock-opname/breakage ambiguity result shape, scanner focus lifecycle, product-search interaction, visible feedback, and row-level serial dialog patterns being adapted.
- [x] 1.3 Add collision fixtures and resolver tests covering duplicate product barcodes plus product/conversion/serial cross-type exact matches without relying on database ordering.

## 2. Establish One Authoritative Entry Coordinator

- [x] 2.1 Consolidate transfer scan input, exact resolution, ambiguity state, search selection, row mutation, feedback, and queue completion into one Livewire entry-state owner while preserving the parent form's row-data contract.
- [x] 2.2 Preserve keyed remount behavior so authoritative origin or creation-condition changes clear rows and rebuild entry state, while destination-only changes preserve current rows.
- [x] 2.3 Remove or retire obsolete sibling mutation and acknowledgement paths after focused create/edit parity tests prove the coordinator owns each operation outcome exactly once.

## 3. Implement Exact Resolution and Ambiguity

- [x] 3.1 Extend the transfer resolver to collect all exact product-barcode, conversion-barcode, and serial candidates and return not-found, uniquely resolved, or ambiguous outcomes without fixed type precedence.
- [x] 3.2 Filter exact candidates by global product validity (active and stock-managed), tenant-owned origin location, selected condition, current stock eligibility, valid whole-unit conversion, and canonical serial availability before returning an outcome.
- [x] 3.3 Project minimal permission-safe ambiguity candidate identities, with detailed operational context only for operators allowed to view system stock.
- [x] 3.4 Add ambiguity choose and cancel mutations that re-resolve canonical identity, leave rows unchanged on cancellation or stale eligibility, and restore scanner focus.
- [x] 3.5 Add an accessible ambiguity dialog adapted from adjustment/breakage, including keyboard operation, visible selection state, and clear Bahasa Indonesia feedback.

## 4. Separate Product Search from Scanning

- [x] 4.1 Replace the hybrid scan/search behavior with a dedicated exact scanner field and an explicit “Cari Produk” interaction; unknown exact scans must not fall through to or auto-select tokenized results.
- [x] 4.2 Implement debounced tokenized product search across product name, code, barcode, category, and brand, constrained to stock-managed products eligible at the authoritative origin and condition.
- [x] 4.3 Keep search result public state minimal and remove protected quantities, maxima, shortages, allocations, location/condition provenance, and tax provenance for blind operators.
- [x] 4.4 Authoritatively revalidate selected product identity before adding a new non-serialized row with one base unit, adding a serialized row at zero, or focusing an existing row without incrementing it.
- [x] 4.5 Add an accessible product-search dialog with empty, loading, result, no-result, close, row-focus, and scanner-focus-restoration states.

## 5. Adapt Serialized Row Management

- [x] 5.1 Make serialized product-barcode scans and product-search selections create or focus a zero-quantity row without synthesizing a serial or incrementing quantity.
- [x] 5.2 Keep exact serial scans authoritative and derive serialized row quantity only from unique selected canonical serial identities.
- [x] 5.3 Add row-level serial management to review and remove selected serials and search existing eligible serials for the row's product, origin, and condition.
- [x] 5.4 Reject raw or missing serial text and revalidate tenant, product, origin, condition, status, dispatch reservation, return-process state, and cross-row uniqueness on every serial add or removal outcome.
- [x] 5.5 Verify blind serial dialog state and feedback expose no availability, reservation, location, condition, or tax provenance.

## 6. Preserve FIFO, Idempotency, Feedback, and Focus

- [x] 6.1 Move the proven synchronous capture-and-clear FIFO controller to the authoritative entry coordinator and retain session-lifetime server-side atomic operation claims.
- [x] 6.2 Pause later queued scans while ambiguity requires a choice, then resume in capture order after choose or cancel without losing or duplicating an operation.
- [x] 6.3 Keep product-search modal input isolated from scanner capture and ensure Livewire initialization/morph hooks bind the current scanner element only once.
- [x] 6.4 Provide visible accessible success, warning, duplicate, and rejection feedback for product, conversion, and serial outcomes, with neutral non-quantitative Bahasa Indonesia messages for blind operators.
- [x] 6.5 Restore scanner focus after resolved, rejected, duplicate, cancelled, search-selected, serial-managed, and modal-close outcomes without stealing focus while an interactive dialog remains open.

## 7. Focused Verification

- [x] 7.1 Run focused resolver and Livewire tests for exact collisions, invalid conversions, unknown scans, ambiguity selection/cancellation, stale candidates, authoritative search selection, and no fuzzy auto-add.
- [x] 7.2 Run focused transfer create/edit tests for non-serialized accumulation, serialized zero-quantity rows, serial add/remove/search, duplicate prevention, origin/condition remount, destination preservation, and parent row synchronization.
- [x] 7.3 Run focused stock-visibility and UI-feedback tests using sentinel protected values across scan, ambiguity, search, serial dialog, queue, and error projections.
- [x] 7.4 Perform a real-browser focused check with scanner-like rapid Enter submissions, including scans captured before feedback, an ambiguity pause with later queued scans, modal cancellation/selection, Livewire morphs, and final focus restoration.
- [x] 7.5 Run the relevant focused Adjustment/Transfer and cross-module transfer regression files only, record commands and results, and leave full-suite verification outside this change.
