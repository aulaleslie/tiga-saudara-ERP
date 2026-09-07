## 1. Draft schema and compatibility boundary

- [x] 1.1 Inspect applicable repository instructions, adjustment lifecycle entry points, serial lookup normalization/scopes, and conversion quantity precision; confirm implementation details against the design without expanding approval scope.
- [x] 1.2 Add a nullable versioned count_draft JSON field with MySQL/SQLite-compatible migration and model handling; define validated row, serial, condition, location, and baseline structures.
- [x] 1.3 Add a legacy pending-adjustment read adapter preserving quantities/serials as proposed good counts, and restrict count editing to authorized pending normal documents.
- [x] 1.4 Guard every legacy approval path against new-format count proposals before mutation; preserve legacy-format behavior and expose a clear pending-approval-implementation message.

## 2. Product resolution and counting rules

- [x] 2.1 Implement adjustment-specific stock-managed tokenized product lookup using globalSearch without the purchase is_sold filter; normalize identity, base unit, and serial requirement from database records.
- [x] 2.2 Implement exact product-barcode, conversion-barcode, and existing-serial resolution, returning candidate choices for ambiguous interpretations without stock mutations or serial status/location eligibility restrictions.
- [x] 2.3 Implement one-row-per-product counting: zero search initialization, ordinary barcode plus one, conversion-factor base-unit increments, no serialized-barcode increment, and existing-row focus on search selection.
- [x] 2.4 Implement product-scoped serial text deduplication across conditions, raw row-dialog serial entry, optional matching source references, explicit reclassification/removal, and server-derived serial counts.
- [x] 2.5 Implement Good/Bad input targeting and server-derived PKP allocation from the selected location's setting; preserve both condition counts on toggle changes.

## 3. Create/edit interface

- [x] 3.1 Replace the old location loader with purchase receiving's searchable standard-location dropdown; bridge its event, retain a single form location value, and confirm/reset all counting state on populated location changes.
- [x] 3.2 Add the main scan field, tokenized product-search dialog, and ambiguous-match dialog with clear success/duplicate/not-found feedback and focus restoration; process consecutive scans in order without dropping repeated ordinary barcodes.
- [x] 3.3 Build compact product rows with editable ordinary good/bad counts, read-only serialized counts, base units, and a POS-style serial action/dialog accepting known and unregistered row serials.
- [x] 3.4 Display existing/proposed good and bad counts, signed differences, baseline capture time, source serial context, and proposed conditions without claiming approval reconciliation.

## 4. Persistence and restoration

- [x] 4.1 Implement shared transactional create/update validation and persistence for versioned pending proposals, recomputing quantities/tax allocation and preserving explicit zeros, raw serials, and omitted-product boundaries without inventory writes.
- [x] 4.2 Persist edit location changes consistently with the proposal; restore saved and failed-validation inputs including counts, conditions, serial text, date, and note without reinitializing from stock.
- [x] 4.3 Preserve existing legacy records/details, integrate pending notification conventions, and add only the minimal pending-document identification/count presentation needed for new-format drafts on existing show/list surfaces.

## 5. Focused verification and human handoff

- [x] 5.1 Add/run focused resolver and counting tests for actual search payloads, token matching, barcode/conversion increments, ambiguity, serialized zero counts, unknown main versus row serials, duplicates across conditions, and identical serial text on different products.
- [x] 5.2 Add/run focused Livewire and persistence tests for location/PKP behavior, Good/Bad changes, serial-derived totals, baseline comparisons, zero/omitted rows, legacy adaptation, edit restoration, permissions/pending guards, and unchanged stock/serial/history state on save.
- [x] 5.3 Add/run focused migration and approval-compatibility tests, including new-format rejection with no mutation and representative unchanged legacy behavior; run existing focused purchase/breakage selection checks only where shared integration warrants them.
- [x] 5.4 Prepare a human browser checklist for create/edit location-label persistence, scanner Enter/focus behavior, fast repeated scans, search and ambiguity dialogs, row serial entry, Good/Bad switching, comparisons, and validation restoration. Browser execution is performed by the human; do not introduce automated browser tests.
- [x] 5.5 Report focused command results and remaining approval dependency. Do not run or plan a full test suite; distinguish prepared human checks from checks the human has actually completed.
