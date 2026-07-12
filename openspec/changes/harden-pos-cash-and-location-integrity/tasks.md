## 1. Cash Ledger Regression Coverage

- [ ] 1.1 Add a checkout regression test for Rp5,000,000 opening cash, Rp780,000 grand total, Rp800,000 cash tender, and Rp20,000 change, asserting Rp800,000 `CASH_SALE_IN`, Rp20,000 `CHANGE_OUT`, and Rp5,780,000 cached and recalculated expected cash.
- [ ] 1.2 Update existing cash-overpayment tests that encode grand-total cash inflow followed by a second change deduction so they assert physical tender and change events instead.
- [ ] 1.3 Add or extend focused cases for exact cash, non-cash-only, mixed-payment cash change, and idempotent finalization replay.

## 2. Cash Tender and Expected-Cash Correction

- [ ] 2.1 Change single-cash checkout finalization to derive `CASH_SALE_IN` from the validated cash amount tendered rather than the checkout grand total.
- [ ] 2.2 Change multi-payment checkout finalization to record the validated sum of cash components as cash inflow without capping it at the checkout grand total.
- [ ] 2.3 Keep `CHANGE_OUT` as the single change deduction and update the session expected-cash cache using exactly the amounts persisted to the IN and OUT ledger events.
- [ ] 2.4 Verify non-cash-only checkout creates no drawer events and checkout replay creates no duplicate cash events or expected-cash mutation.
- [ ] 2.5 Confirm session summary and reconciliation outputs remain aligned with cash tender minus change and do not introduce a second change deduction.

## 3. Owner-Scoped Location Onboarding

- [ ] 3.1 Replace new-location assignment to every setting with an idempotent enabled `SettingSaleLocation` assignment for the location's owning `setting_id` only.
- [ ] 3.2 Wrap standard location creation and the quick-add location flow in database transactions so required assignment failure does not report or retain a partially onboarded location.
- [ ] 3.3 Preserve explicit cross-business location enablement through the existing sale-location configuration flow.
- [ ] 3.4 Ensure ownership changes keep the new owner's required assignment enabled without silently enabling unrelated businesses.

## 4. Sale-Location Cache Consistency

- [ ] 4.1 Invalidate the owning setting's concrete `SalesLocationResolver` cache key after new-location assignment instead of calling no-argument invalidation.
- [ ] 4.2 Audit bulk insert/update paths affecting `setting_sale_locations` and add explicit affected-setting invalidation wherever model events are bypassed.
- [ ] 4.3 Add a standard-create test that warms the resolver cache first and then asserts immediate POS visibility for the owner and exclusion from an unrelated setting.
- [ ] 4.4 Add equivalent quick-add and explicit cross-business enable/disable cache tests, including absence of duplicate assignments.
- [ ] 4.5 Add an atomic-failure test proving a required assignment failure cannot leave a successfully created but POS-unavailable location.

## 5. Verification and Release Safety

- [ ] 5.1 Run focused POS checkout finalization, expected-cash calculator, session summary, and reconciliation tests.
- [ ] 5.2 Run focused Setting location creation and sale-location configuration tests.
- [ ] 5.3 Run `composer test:fresh-sqlite` or the proportionate project test command and document any unrelated pre-existing failures.
- [ ] 5.4 Confirm no migration rewrites closed or finalized POS session cash events and no barcode or product-scan behavior is changed.
