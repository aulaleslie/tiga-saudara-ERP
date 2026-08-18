## 1. Establish the shared resolver

- [x] 1.1 Add a protected resolver method to `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php` that accepts a settlement line array and returns the target-purchase-derived value, or `null` when no target is set or no unit price can be resolved for the line's product
- [x] 1.2 Inside the resolver, look up the target purchase's unit price for the line's product using the existing `unpaidPurchases` list keyed by `product_id` and `MODIFY_PURCHASE`, matching on `target_purchase_id` (the same source the current `.target_purchase_id` branch uses)
- [x] 1.3 Compute the value as unit price × `(float) ($line['quantity'] ?? 1)`, preserving the `?? 1` default so serialized lines (which carry no `quantity` key) resolve to a single unit
- [x] 1.4 Return `null` rather than `0` when the target purchase has no matching detail or the price is unresolvable, so callers can distinguish "no answer" from "zero"
- [x] 1.5 Add a protected helper that reports whether a settlement line is eligible for recomputation, returning true only for `DRAFT` status (the existing `$isEditable` check already covers `DRAFT` and `REJECTED`; reuse that convention)

## 2. Apply the resolver at every association point

- [x] 2.1 In the `.target_purchase_id` branch of `updatedSettlementLines()`, replace the inline calculation with a call to the resolver and remove the `min($newNominal, $maxNominal)` cap so the derived value is assigned uncapped
- [x] 2.2 In the `.method` branch, after the serialized auto-select assigns `target_purchase_id` from `origin_purchase_id`, call the resolver and assign the result when non-null and the line is eligible
- [x] 2.3 In the `.method` branch, after the non-serialized auto-select assigns `target_purchase_id` from `origin_purchase_id`, call the resolver and assign the result when non-null and the line is eligible
- [x] 2.4 In `mount()`, after `loadUnpaidPurchases()` has populated the lookup list, recompute the nominal for every eligible line that already has a `target_purchase_id` (whether restored from a saved settlement or auto-assigned by the `$defaultTargetPurchaseId` logic)
- [x] 2.5 Verify the `mount()` recomputation runs after `loadUnpaidPurchases()`, since the resolver depends on that list being populated
- [x] 2.6 Confirm no recomputation path alters lines whose `method` is not `MODIFY_PURCHASE`, or whose `target_purchase_id` is empty

## 3. Align submission validation

- [x] 3.1 In `rulesForLineSubmit()`, derive the validation ceiling from the resolver when the line is a targeted `MODIFY_PURCHASE` line, falling back to `max_nominal` otherwise
- [x] 3.2 Confirm the untargeted path still validates against `max_nominal` unchanged
- [x] 3.3 Check whether the bulk `rules()` method (which also caps at `max_nominal`) is used on any submit path, and align it the same way if so

## 4. Test the targeted pricing rule

- [x] 4.1 Add a test asserting a targeted line is valued at target purchase `unit_price` × quantity when the target price is **higher** than the return line's stored value (the case the removed `min()` previously suppressed)
- [x] 4.2 Add a test asserting a targeted line resolves to a non-zero value when `max_nominal` is `0`, simulating a return created with an unresolved catalogue price
- [x] 4.3 Add a test asserting a line whose target purchase has no matching product detail keeps its existing value and does not become zero
- [x] 4.4 Verify the existing lower-price assertion in `Modules/PurchasesReturn/Tests/Feature/PurchaseReturnSettlementPhase1Test.php` (target price 8 × qty 10 = 80 against a stored 1000) still passes unchanged

## 5. Test recomputation coverage across association points

- [x] 5.1 Add a test asserting a serialized line auto-selected via `origin_purchase_id` is repriced from that purchase without any manual target re-selection
- [x] 5.2 Add a test asserting a non-serialized line auto-selected via `origin_purchase_id` is repriced from that purchase without any manual target re-selection
- [x] 5.3 Add a test asserting a settlement form opened with a previously saved draft target purchase presents a recomputed value on load
- [x] 5.4 Add a test asserting a serialized line belonging to a multi-quantity return detail is valued at one unit's price, not multiplied by the detail quantity

## 6. Test eligibility and untargeted boundaries

- [x] 6.1 Add a test asserting a `SUBMITTED` line retains its stored nominal when the form is opened
- [x] 6.2 Add a test asserting an `APPROVED` line retains its stored nominal when the form is opened
- [x] 6.3 Add a test asserting a `MODIFY_PURCHASE` line with no target purchase keeps its stored value and existing ceiling
- [x] 6.4 Add a test asserting a targeted line whose derived value exceeds `max_nominal` passes submission validation
- [x] 6.5 Add a test asserting an untargeted line exceeding `max_nominal` is still rejected on submission

## 7. Verify the cancellation outcome end to end

- [x] 7.1 Add a test covering the driving scenario: a full-quantity return against an unpaid source purchase, every line settled with `MODIFY_PURCHASE` targeting that purchase, asserting the settlement total equals the purchase's line total
- [x] 7.2 Extend that test through approval, asserting the source purchase's `due_amount` reaches zero
- [x] 7.3 Confirm the approval path's existing `min($itemAmount, $purchase->due_amount)` cap and `SupplierCredit` overflow behaviour are exercised, not modified, by these tests

## 9. Align approval-time validation

- [x] 9.1 In `PurchasesReturnSettlementController::approveItem()`, exempt targeted `MODIFY_PURCHASE` settlements from the return detail `sub_total` ceiling, which would otherwise reject the values this change produces
- [x] 9.2 Key that exemption on `target_purchase_id` alone, excluding `detail->po_id`, so untargeted settlements on lines with a recorded originating purchase keep the `sub_total` ceiling
- [x] 9.3 Confirm the pre-existing `total_amount` check still bounds targeted settlements, and that its separate `?? po_id` fallback for resolving which purchase to apply to is left unchanged
- [x] 9.4 Add a test asserting a targeted settlement above the return line's subtotal but within the target purchase's total is approved
- [x] 9.5 Add a test asserting a targeted settlement exceeding the target purchase's `total_amount` is rejected at approval
- [x] 9.6 Add a test asserting an **untargeted** `MODIFY_PURCHASE` settlement on a detail with a non-null `po_id`, valued above the return line's subtotal, is still rejected at approval
- [x] 9.7 Add a test asserting a non-`MODIFY_PURCHASE` settlement above the return line's subtotal is still rejected at approval
- [x] 9.8 Verify test 9.6 fails against the pre-fix condition and passes against the fix, confirming it pins the exemption boundary rather than passing vacuously

## 10. Correct the price source and the browser-side overwrite

- [x] 10.1 Derive `product_unit_price` in `loadUnpaidPurchases()` from `sub_total / quantity` instead of the `unit_price` column, which can hold a list price or a different unit basis
- [x] 10.2 Recompute `max_nominal` alongside `nominal` so the displayed ceiling tracks the repriced value
- [x] 10.3 Route all three repricing call sites through a single `applyTargetPurchasePricing()` helper
- [x] 10.4 Delete the Blade `change` handler that stamped the nominal input from the stale `data-max-nominal` attribute
- [x] 10.5 Add a test asserting the value derives from `sub_total`, not `unit_price`, when the two disagree (mirrors purchase 18587)
- [x] 10.6 Add a test covering a UOM-converted line where `unit_price` is a different unit basis
- [x] 10.7 Add a test asserting `max_nominal` is recomputed alongside `nominal`
- [x] 10.8 Verify all three new tests fail against the `unit_price` basis and pass against the `sub_total` basis
- [x] 10.9 Confirm no regressions in `Modules/PurchasesReturn/Tests/Feature/` against the recorded baseline
- [x] 10.10 Route `ensurePurchaseInList()` through the same derivation — it builds a second dropdown payload and still read `unit_price`, reintroducing the wrong basis on the serialized auto-select path
- [x] 10.11 Extract `derivePurchaseUnitPrice()` so both payload builders share one basis and cannot drift again
- [x] 10.12 Add a test covering the injected-payload path (purchase outside `loadUnpaidPurchases()`'s status filter), verified to fail against the `unit_price` basis
- [x] 10.13 Add `selectTargetPurchase()` and call it from the nota dropdown, so a deferred entangle no longer leaves the selection unseen by the server
- [x] 10.14 Revert the `.live` entangle attempt, which re-rendered the Alpine dropdown mid-interaction and broke purchase selection
- [x] 10.15 Replace `x-bind:value` on the nominal input with `x-effect` writing the DOM property, so a repriced value is visible; seed the initial render via the `value` attribute
- [x] 10.16 Add a test asserting a manually selected target reprices a line whose detail has no `po_id` (mirrors purchase return 6)

## 8. Regression and verification

- [x] 8.1 Run the purchase return settlement suite: `php artisan test --filter=PurchaseReturnSettlement`
- [x] 8.2 Run the serial auto-select suite: `php artisan test --filter=PurchaseReturnSerialSettlementAutoSelect`
- [x] 8.3 Run `php artisan test --filter=ModifyPurchaseInvalidatesPayments` to confirm the payment-invalidation path is unaffected
- [x] 8.4 Run `php artisan test --filter=PurchaseReturn` to cover the approval, item-approval, and lifecycle suites that share the amended controller path
- [x] 8.5 Manually verify on `/purchase-returns/{id}/settlement` that selecting `Ubah Nota Pembelian` with an auto-selected nota shows a `Nilai Penyelesaian` matching that nota, without re-selecting it by hand
