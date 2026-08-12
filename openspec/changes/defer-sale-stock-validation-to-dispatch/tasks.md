## 1. Sales-order stock-gating change

- [ ] 1.1 Remove the aggregate stock-availability preflight from the standard `SaleService::createSale()` persistence path, including obsolete helper code if it has no remaining callers.
- [ ] 1.2 Confirm the editable standard Sales update path does not introduce an equivalent aggregate stock check and preserves its existing status/authorization restrictions.
- [ ] 1.3 Preserve all existing standard Sales normalization, bundle-line persistence, cost snapshots, document references, tax validation, and inventory non-mutation behavior.

## 2. Dispatch fulfillment safeguards

- [ ] 2.1 Preserve dispatch-submission validation for authoritative remaining sale quantities, selected allowed locations, non-serial location stock, bundle components, serial state/tax/location, and pending serial reservations.
- [ ] 2.2 Preserve the locked dispatch-approval recheck, atomic inventory deduction, inventory transaction recording, and serial lifecycle updates when stock changes after submission.

## 3. Regression coverage and verification

- [ ] 3.1 Replace standard Sales HTTP and Livewire tests that expect insufficient stock to block sale creation with assertions that the Sales document and lines are persisted without inventory mutation.
- [ ] 3.2 Add or adapt bundle coverage to prove insufficient stock-managed bundle components no longer block standard Sales create or editable update.
- [ ] 3.3 Verify dispatch submission still rejects a saved zero/insufficient-stock standard Sales order at the selected location without creating a dispatch.
- [ ] 3.4 Run focused Sales and dispatch feature tests, including approval-time insufficient-stock coverage; run the relevant SQLite suite if the focused tests pass.
