## 1. POS Transaction Unification

- [x] 1.1 Update `PosTransactionService::completeFromCartSnapshot` to remove `linesBySetting` grouping.
- [x] 1.2 Modify the persistence loop to use the `activeSession` setting ID for all transaction creating/updating.
- [x] 1.3 Ensure `snapshot_totals` reflects the entire cart combined.
- [x] 1.4 Link the unified transaction to `checkoutId`.

## 2. Inventory Mutation Localization

- [x] 2.1 Update `InlinePosCheckoutPostingAdapter::post` to utilize `source_setting_id` from allocation chunks.
- [x] 2.2 Modify `Transaction::create` call to use the chunk-specific setting ID instead of the global context setting.
- [x] 2.3 Verify `previous_quantity` and `after_quantity` calculations correctly reference the respective stock owner's history (Refined: used setting-specific totals instead of global totals).

## 3. Verification & Cleanup

- [ ] 3.1 Verify that Setting B's inventory log shows correct deductions for cross-tenant sales.
- [ ] 3.2 Verify that Setting A's POS history contains a single unified transaction.
- [ ] 3.3 Ensure code generation sequences correctly increment only in the active setting.
