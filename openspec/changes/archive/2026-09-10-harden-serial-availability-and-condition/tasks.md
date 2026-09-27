## 1. Canonical Serial Domain Rules

- [x] 1.1 Audit every `ProductSerialNumber` availability query and condition/status writer across Product Detail, Stok Lintas Bisnis, generic autocomplete, stock transfer, POS, return, recovery, and history flows; record whether each call site is an operational selector, mutation boundary, or audit lookup before changing it.
- [x] 1.2 Add typed, composable Eloquent scopes on `ProductSerialNumber` for operationally available, sellable, and available-broken serials, covering dispatch/return flags, legacy null status, legacy `BROKEN`, and all known unavailable lifecycle states.
- [x] 1.3 Add a shared Bahasa Indonesia serial-state presenter that combines lifecycle and physical condition without treating an active broken serial as sellable or a missing serial's location as current inventory.
- [x] 1.4 Align in-scope condition-only write paths with the canonical `ACTIVE + is_broken` representation while retaining read compatibility for legacy `status=BROKEN` rows and avoiding a bulk data migration.

## 2. Product Detail and Cross-Business Stock

- [x] 2.1 Refactor Product Detail serial queries to use the canonical scopes and expose distinct sellable, available-broken, missing, returning, and historical/unavailable views without hiding audit evidence.
- [x] 2.2 Update Product Detail counts, filters, and Bahasa Indonesia labels so active-good, active-broken, and missing serials appear in their correct categories with last-known location shown only as provenance for missing rows.
- [x] 2.3 Refactor Stok Lintas Bisnis Good and Bad serial dialog queries to use the sellable and available-broken scopes while retaining existing business and location boundaries.
- [x] 2.4 Add a non-mutating discrepancy signal for serialized ProductStock bucket counts that do not reconcile with the corresponding scoped serial dialogs, without changing inventory balances.

## 3. Autocomplete and Stock Transfer

- [x] 3.1 Update generic serial autocomplete call sites according to the audit classification so operational selectors declare good or broken availability explicitly while history, return, and recovery lookups preserve their workflow-specific visibility.
- [x] 3.2 Update stock-transfer scan and selection paths so normal mode accepts only sellable serials, broken mode accepts only available-broken serials, and both reject missing or otherwise unavailable serials with Bahasa Indonesia feedback.
- [x] 3.3 Revalidate current serial lifecycle, condition, location, dispatch, and return state at transfer draft/submission and authoritative dispatch or return-dispatch mutation boundaries, preserving atomic failure behavior.

## 4. POS Discovery and Assignment

- [x] 4.1 Apply the sellable scope to POS serial suggestions and exact-scan resolution so missing, broken, sold, returned, returning, or dispatched serials cannot be discovered as sale inventory.
- [x] 4.2 Harden ordinary cart serial lookup and direct assignment against crafted or stale serial input by re-reading the authoritative sellable serial record and allowed source location.
- [x] 4.3 Harden bundle-component serial lookup and assignment with the same sellability, case-insensitive identity, source-location, uniqueness, and required-quantity rules used for ordinary lines.

## 5. POS Preflight and Atomic Posting

- [x] 5.1 Refactor checkout preflight and allocation validation to derive serial fulfillment from authoritative assigned serial records, rejecting missing, broken, returning, dispatched, sold, returned, or out-of-scope serials with line-level diagnostics.
- [x] 5.2 Add final locked sellability revalidation for ordinary and bundle-component serials immediately before sale posting mutations, with complete rollback if any assigned serial changed state after cart assignment.
- [x] 5.3 Review the updated Product, report, transfer, and POS queries for bounded eager loading and add query-count coverage where a loop could introduce serial-dependent N+1 queries.

## 6. Focused Verification and Human Browser Checklist

- [x] 6.1 Add focused model tests for all shared availability scopes, including active-good, active-broken, null-status compatibility, legacy `BROKEN`, missing, sold, returned, returning, and dispatched cases.
- [x] 6.2 Add focused Product Detail and Stok Lintas Bisnis tests reproducing five sellable, one available-broken, and three missing serials, including correct Bahasa Indonesia labels, counts, dialogs, and missing provenance.
- [x] 6.3 Add focused autocomplete and stock-transfer tests for normal/broken modes, missing exclusion, workflow-specific audit/return visibility, and stale-state rejection at mutation boundaries.
- [x] 6.4 Add focused POS tests for suggestions, exact scans, ordinary assignment, bundle-component assignment, case-insensitive matching, and rejection of active-broken or unavailable serials.
- [x] 6.5 Add focused POS preflight and final-posting tests proving stale transitions to broken or missing fail atomically with no partial sale, payment, stock, transaction, dispatch, or serial mutation.
- [x] 6.6 Run only the relevant focused Product, report, transfer, POS, and serial regression suites; do not plan or require a full application test-suite run.
- [x] 6.7 Create a Bahasa Indonesia human browser checklist covering Product Detail, Stok Lintas Bisnis, transfer selection, and POS flows for both normal and broken/missing serial cases; mark every item pending for human execution and do not run automated browser tests.
