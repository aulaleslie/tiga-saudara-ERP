## 1. Baseline Characterization

- [x] 1.1 Add focused resolver tests that characterize exact primary barcode, exact conversion barcode, exact serial, and no-exact-match precedence for the active tenant and origin.
- [x] 1.2 Add focused Livewire tests that characterize tokenized name, code, barcode, category, and brand search, including ambiguous results and good/broken origin availability filtering.
- [x] 1.3 Add focused tests that capture current sequential row accumulation, serial-derived quantity, duplicate handling, focus-restoration events, and blind versus privileged feedback before corrections are introduced.

## 2. Authoritative Scan Intent

- [x] 2.1 Define minimal internal scan-intent payloads for product ID, conversion ID, and serial ID and stop dispatching raw Eloquent product data or client-derived stock and provenance as mutation authority.
- [x] 2.2 Refactor the product-table entry boundary to reload the active tenant-owned origin, current form condition, active stock-managed product, and applicable condition-specific stock before adding or incrementing a row.
- [x] 2.3 Make exact barcode resolution condition-aware using the same canonical good/broken availability interpretation as search, table mutation, allocation, and final validation.
- [x] 2.4 Reject crafted product-selection payloads that attempt to control serialization, condition, scan multiplier, stock, allocation, or other server-derived fields, leaving existing rows unchanged.

## 3. Conversion Normalization

- [x] 3.1 Reload conversion records by canonical ID at the receiving mutation boundary and verify current product association and active tenant scope.
- [x] 3.2 Accept only finite positive whole-number conversion factors and apply the authoritative integer factor directly to requested base quantity without truncation, rounding, or fallback coercion.
- [x] 3.3 Add focused tests for repeated valid conversion scans, changed or removed conversions, zero, negative, fractional, missing, cross-product, and crafted-multiplier cases.

## 4. Serial Mutation Safety

- [x] 4.1 Unify scanned and autocomplete serial selection behind one authoritative table mutation that reloads the canonical serial record.
- [x] 4.2 Validate serial tenant product ownership, row product, selected origin, current availability, dispatch reservation, return-process state, transfer condition, and uniqueness across all rows before changing selection or quantity.
- [x] 4.3 Ensure serial rejection leaves products, selected serial identities, and derived quantities unchanged and preserves final save, submit, approval, and dispatch revalidation.
- [x] 4.4 Add focused tests for wrong product, wrong origin, missing, sold, dispatched, returning, condition mismatch, duplicate delivery, cross-row duplicate, and eligibility changes after selection.

## 5. Deterministic Rapid Scanning

- [x] 5.1 Capture and clear each submitted scanner value independently so later typing or debounced search updates cannot replace an already captured value.
- [x] 5.2 Add FIFO coordination for overlapping scanner submissions and advance the queue after success, duplicate, or rejection while restoring scanner focus predictably.
- [x] 5.3 Add an active-form operation token and bounded processed-operation tracking so repeated delivery of one queued operation applies its row effect at most once without suppressing later scans.
- [x] 5.4 Add focused Livewire and interaction tests for rapid product scans, conversion scans, distinct serials, duplicates, mixed accepted/rejected scans, and an item becoming ineligible while queued.

## 6. Visibility and Feedback Regression

- [x] 6.1 Apply the existing transfer stock-visibility projection to every exact-scan, search-selection, queued result, duplicate, and rejection event so blind state contains only operator intent.
- [x] 6.2 Return stable neutral non-quantitative Bahasa Indonesia feedback to blind users while retaining permission-appropriate operational detail for privileged and Super Admin users.
- [x] 6.3 Add sentinel-based focused assertions covering rendered HTML, Livewire public state, dispatched events, validation data, and session feedback for all entry paths and rapid-scan outcomes.

## 7. Focused Verification

- [x] 7.1 Run the focused transfer resolver, search, product-table, origin-gating, UI-feedback, visibility, crafted-request, draft-validation, and stock-transaction test files or equivalent targeted filters.
- [x] 7.2 Perform and record a focused browser interaction check using scanner-like rapid Enter submissions for product, conversion, and serial inputs with blind and privileged accounts.
- [x] 7.3 Confirm the change introduces no schema migration, inventory mutation timing change, movement-document behavior, or requirement to run the full application test suite.
