## 1. Authorization and business-context foundation

- [x] 1.1 Add the `documents.business.override` permission to the established permission configuration and seed/sync path without granting it by default.
- [x] 1.2 Implement a shared effective-document-business resolver that normalizes target setting IDs, resolves user-accessible settings (including Super Admin), checks override permission, and exposes target PKP state.
- [x] 1.3 Add focused tests for authorized, unprivileged, Super Admin, and forged/inaccessible target-business requests.

## 2. Searchable business selector and reactive context

- [x] 2.1 Add a reusable searchable single-business selector following the existing CoreUI/Select2 conventions, including required/error behavior and accessible-business options.
- [x] 2.2 Add selected-business state, initialization, validation, and selector rendering to Purchase CreateForm and EditForm; preserve active-business-only behavior for unprivileged users.
- [x] 2.3 Add selected-business state, initialization, validation, and selector rendering to Sale CreateForm and EditForm; preserve active-business-only behavior for unprivileged users.
- [x] 2.4 Pass selected business context through Purchase/Sale product search, product cart, and tax controls, using refreshed keys/props where required.
- [x] 2.5 Implement target-business change handling that preserves non-tax cart values and rehydrates/removes only tax-related state and UI.

## 3. Purchase persistence and draft reassignment

- [x] 3.1 Use the resolved effective business for Purchase create PKP lookup, scoped uniqueness validation, normalization, reference generation, and persisted `setting_id`.
- [x] 3.2 Enforce draft-only Purchase business reassignment in the server-side update path and atomically generate a new target-business purchase reference when the setting changes.
- [x] 3.3 Preserve existing Purchase non-draft lifecycle restrictions and update cross-business success feedback to name the target business and reference.

## 4. Sale persistence and draft reassignment

- [x] 4.1 Use the resolved effective business for Sale create PKP lookup, cart validation/normalization, reference generation, and persisted `setting_id`.
- [x] 4.2 Enforce draft-only Sale business reassignment in the service/update path and atomically generate a new target-business sale reference when the setting changes.
- [x] 4.3 Preserve existing Sale non-draft lifecycle restrictions and update cross-business success feedback to name the target business and reference.

## 5. Verification

- [x] 5.1 Add Purchase Livewire/feature coverage for selector visibility and requirement, business authorization, selected-business persistence, and unchanged active session/list redirect.
- [x] 5.2 Add Sale Livewire/feature coverage for selector visibility and requirement, business authorization, selected-business persistence, and unchanged active session/list redirect.
- [x] 5.3 Add PKP-to-non-PKP and non-PKP-to-PKP cart rehydration tests proving prices, quantities, discounts, and shipping are retained while tax state is correctly removed or required.
- [x] 5.4 Add draft move and rejected-then-drafted move tests for Purchase and Sale, including target-prefix renumbering, atomic failure behavior, and blocked non-draft moves.
- [x] 5.5 Run the focused Purchase/Sale test suites, then `composer test:fresh-sqlite` or an equivalent full verification pass; document baseline failures from unrelated modules without marking them as resolved by this change.

## 6. Reference Allocation and Concurrency Hardening

- [x] 6.1 Implement real save-path tests that seed valid carts, invoke submit(), and cover PKP validation scenarios (no tax fails, matching tax succeeds).
- [x] 6.2 Fix reference allocation by creating DocumentReferenceService that keeps the Setting lock held from allocation through INSERT, ensuring atomic sequential numbering.
- [x] 6.3 Add rapid sequential tests using the new service that verify sequential creates produce unique, sequential references under Setting row locking.
- [x] 6.4 Update test descriptions to remove misleading "behavioral validation tests" terminology and accurately describe what each test verifies.

## 7. Final Atomicity and Bypass-Path Fixes

- [x] 7.1 Move Sale cross-business draft update reference allocation into SaleService::updateSale() to ensure target-Setting lock, reference allocation, setting/reference update, header/details update all happen atomically in a single transaction.
- [x] 7.2 Remove separate DocumentReferenceService::moveSaleToSetting() call from SaleEditForm to prevent orphaned state if second transaction fails.
- [x] 7.3 Add regression test that forces post-move Sale update to fail and asserts sale retains original setting_id and reference (atomicity verification).
- [x] 7.4 Add concurrency test verifying multiple rapid cross-business Sale moves get sequential references under atomic transactions.
- [x] 7.5 Document fallback reference allocation in Sale and Purchase model hooks: note it provides basic concurrency safety but is NOT suitable for high-concurrency scenarios; prefer DocumentReferenceService for production use.

## Verification Summary

**Focused test suites covering cross-business purchase/sale documents:**
- CrossBusinessPurchaseSaleDocumentsTest: 27 tests
- CrossBusinessFormValidationTest: 10 tests
- DocumentReferenceConcurrencyTest: 4 tests
- ReferenceGenerationConcurrencyTest: 6 tests
- SaleAtomicCrossBusinessUpdateTest: 3 tests
- EffectiveDocumentBusinessResolverTest: 9 tests

**Total: 59/59 tests PASSED**
