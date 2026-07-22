## 1. Payment-Term Endpoint

- [x] 1.1 Add focused authenticated POS feature coverage proving the payment-term search endpoint returns existing term IDs, names, and longevity values, including an empty-table response.
- [x] 1.2 Replace the unresolved Setting-module PaymentTerm reference in `PosSellController` with the canonical `Modules\Purchase\Entities\PaymentTerm` model while preserving authorization, ordering, route, and JSON shape.
- [x] 1.3 Run the focused payment-term endpoint tests and confirm unauthorized checkout users remain rejected by the existing permission guard.

## 2. Debt-Term Loading Experience

- [x] 2.1 Add explicit loading, unavailable/empty, and retry affordances for the staged debt-term selector without changing the normal full-payment path.
- [x] 2.2 Update `loadPaymentTerms()` to validate HTTP status and payload shape, populate all valid returned terms, distinguish an empty successful result from failure, and avoid caching failures as an empty success.
- [x] 2.3 Route payment-term load failures to the staged-modal error surface, keep debt checkout continuation disabled until a valid term is available and selected, and allow retry without reloading the POS page.

Frontend JavaScript regression tests were removed from this change's scope because the repository has no frontend test runner or DOM test harness. The focused backend endpoint and validation suites remain the automated coverage for this change; browser behavior remains in manual UAT task 4.3.

## 3. Staged-Modal Dismissal and Reset

- [x] 3.1 Give the header × and footer **Batal** controls a shared Bootstrap-compatible, non-destructive dismissal contract and remove payment-chain deletion from the header close handler.
- [x] 3.2 Add a separately labelled destructive payment-chain reset control with cashier confirmation, using the existing DELETE endpoint only after confirmation.
- [x] 3.3 On reset success, clear the matching client staged-payment/debt context and close the modal; on decline or request failure, preserve session/client state and show an actionable error when applicable.
- [x] 3.4 Refactor processing-state handling to disable or hide every dismiss and reset control for the full duration of stage submission and checkout finalization, then restore them consistently.

Frontend modal-event regression tests were removed from this change's scope because the repository has no frontend test runner or DOM test harness. Introducing Vitest/Jest/Playwright solely for this fix is explicitly out of scope; browser behavior remains in manual UAT task 4.3.

## 4. Recovery Regression and Verification

- [x] 4.1 Add focused backend recovery coverage using the current authenticated cart-token contract to prove a committed chain remains available across subsequent requests and an explicit reset removes it. Do not repair the obsolete `tests/Feature/PosMultiStagedPaymentReloadRecoveryTest.php` suite, which targets retired `/api/pos/...` routes, a `sale_id` payload, and stale fixtures.
- [x] 4.2 After task 4.1, run the supported focused suites. Current baseline: `PosPaymentTermSearchTest` 3/3, `POSPaymentValidationRulesTest` 11/11, `POSDebtCheckoutTest` 7/7, and `POSPermissionRoleMappingTest` 4/4 pass when run independently.
- [ ] 4.3 Perform browser/UAT verification with production-like payment terms for term selection, visible load failure and retry, ×/**Batal** dismissal, explicit reset confirmation, and processing locks. (OPEN: Manual browser verification required - to be done after code review)
- [x] 4.4 Run `composer test:fresh-sqlite` for the higher-confidence regression pass and record unrelated failures separately. Baseline on 2026-07-22: 1,940 passed, 263 failed, and 4 skipped (8,160 assertions; 204.12 seconds). The runner successfully locked and recreated `database/testing.sqlite`; failures span unrelated legacy fixtures, schemas, permissions, imports, reports, returns, tax behavior, and POS suites, so making the repository-wide suite green is outside this change's scope.
