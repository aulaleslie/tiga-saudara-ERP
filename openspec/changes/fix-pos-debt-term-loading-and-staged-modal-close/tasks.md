## 1. Payment-Term Endpoint

- [x] 1.1 Add focused authenticated POS feature coverage proving the payment-term search endpoint returns existing term IDs, names, and longevity values, including an empty-table response.
- [x] 1.2 Replace the unresolved Setting-module PaymentTerm reference in `PosSellController` with the canonical `Modules\Purchase\Entities\PaymentTerm` model while preserving authorization, ordering, route, and JSON shape.
- [x] 1.3 Run the focused payment-term endpoint tests and confirm unauthorized checkout users remain rejected by the existing permission guard.

## 2. Debt-Term Loading Experience

- [x] 2.1 Add explicit loading, unavailable/empty, and retry affordances for the staged debt-term selector without changing the normal full-payment path.
- [x] 2.2 Update `loadPaymentTerms()` to validate HTTP status and payload shape, populate all valid returned terms, distinguish an empty successful result from failure, and avoid caching failures as an empty success.
- [x] 2.3 Route payment-term load failures to the staged-modal error surface, keep debt checkout continuation disabled until a valid term is available and selected, and allow retry without reloading the POS page.
- [ ] 2.4 Add frontend-oriented regression coverage for successful population, empty results, non-2xx/invalid responses, network failures, retry, and fail-closed submission validation. (OPEN: Requires dedicated frontend test infrastructure)

## 3. Staged-Modal Dismissal and Reset

- [x] 3.1 Give the header × and footer **Batal** controls a shared Bootstrap-compatible, non-destructive dismissal contract and remove payment-chain deletion from the header close handler.
- [x] 3.2 Add a separately labelled destructive payment-chain reset control with cashier confirmation, using the existing DELETE endpoint only after confirmation.
- [x] 3.3 On reset success, clear the matching client staged-payment/debt context and close the modal; on decline or request failure, preserve session/client state and show an actionable error when applicable.
- [x] 3.4 Refactor processing-state handling to disable or hide every dismiss and reset control for the full duration of stage submission and checkout finalization, then restore them consistently.
- [ ] 3.5 Add regression coverage proving × and **Batal** close without DELETE, confirmed reset deletes once, declined/failed reset preserves state, and every exit control is unavailable during processing. (OPEN: Requires dedicated frontend test infrastructure)

## 4. Recovery Regression and Verification

- [ ] 4.1 Extend staged-payment recovery coverage to prove a committed chain survives modal dismissal and is restored on reopen or page reload, while an explicitly reset chain is not recovered. (OPEN: Reload-recovery test suite requires PaymentMethod class resolution)
- [ ] 4.2 Run focused POS debt-checkout, payment-validation, staged-payment, permission, and reload-recovery test suites with `php artisan test` using appropriate filters. (OPEN: Error-handling suite fixed to 2/7 pass; reload-recovery blocked on class resolution)
- [ ] 4.3 Perform browser/UAT verification with production-like payment terms for term selection, visible load failure and retry, ×/**Batal** dismissal, explicit reset confirmation, and processing locks. (OPEN: Manual browser verification required)
- [ ] 4.4 Run `composer test:fresh-sqlite` for the higher-confidence regression pass and record any unrelated pre-existing failures separately. (OPEN: Full suite blocked on pre-existing infrastructure issues)
