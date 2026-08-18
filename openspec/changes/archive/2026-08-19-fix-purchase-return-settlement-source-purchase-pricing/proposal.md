## Why

When a purchase return line is settled with the `Ubah Nota Pembelian` (`MODIFY_PURCHASE`) method against a source purchase, the `Nilai Penyelesaian` shown and saved does not come from that purchase. It is inherited from the return line's stored value, which was written at return-creation time from the product catalogue (`product_prices.last_purchase_price`) rather than from the purchase being returned. The settlement value therefore disagrees with the nota the operator selected.

This blocks a legitimate operational need: returning the full quantity of an unpaid purchase in order to cancel it. The credit should equal the purchase total so the purchase's `due_amount` nets to zero, but today the credit is clamped to an unrelated catalogue-derived ceiling — and when the catalogue lookup resolves to `0`, the settlement value is clamped to zero regardless of the selected purchase.

## What Changes

- Establish a single pricing rule for the `MODIFY_PURCHASE` settlement method: **when a target purchase is selected, that purchase's own line price is the authoritative settlement value**, computed as the target purchase detail's `unit_price` × the settled quantity.
- Recompute the settlement value from the target purchase in every situation where a target becomes associated with a line, not only when the operator changes the dropdown by hand. Today two auto-selection paths assign a target purchase without ever repricing, so the form displays a source nota next to a value that did not come from it.
- Recompute on form hydration, so purchase returns already stored with catalogue-derived values are corrected on view without requiring a data migration.
- **BREAKING (behavioral)**: Remove the ceiling that caps the target-derived value at the return line's stored value. The selected purchase's price becomes the value even when it is higher than the originally recorded amount. The submit-time validation ceiling moves in step, so a correctly repriced value can be saved.
- Leave the untargeted path untouched. `MODIFY_PURCHASE` with no target purchase selected — used when a supplier refunds cash on an already-paid purchase — keeps using the return line's stored value with its existing ceiling.
- Restrict all recomputation to lines in `DRAFT` (or reset-to-draft) status. Lines already `SUBMITTED` or `APPROVED` keep the value they were approved with.

Out of scope, recorded as follow-up work: the return **creation** form still sources line prices from the product catalogue. Because settlement recomputes from the target purchase, that defect no longer affects the settled outcome, but it still produces misleading values on the create and approval screens.

## Capabilities

### New Capabilities
- `purchase-return-settlement-pricing`: Governs how the settlement value for a purchase return line is derived — the authority of a selected source purchase over the line's stored value, when recomputation occurs, which line statuses are eligible, and how untargeted settlements are valued.

### Modified Capabilities

<!-- None. No existing spec covers purchase return settlement valuation. -->

## Impact

**Affected code**

- `app/Livewire/PurchaseReturn/PurchaseReturnSettlementForm.php` — the whole change is contained here:
  - `mount()` line hydration, which auto-assigns a target purchase for non-serial lines
  - `updatedSettlementLines()` `.method` branch, both the serial and non-serial auto-selection paths
  - `updatedSettlementLines()` `.target_purchase_id` branch, which holds the only existing repricing logic
  - `rulesForLineSubmit()`, whose `max` rule must track the recomputed value

- `Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php` — approval-time validation only. The ceiling that rejected any settlement exceeding the return detail's `sub_total` is lifted for settlements with an explicitly selected target purchase, which would otherwise be approvable only at values this change is designed to correct. Those settlements stay bounded by the existing check against the target purchase's `total_amount`.

**Deliberately unaffected**

- How `PurchasesReturnSettlementController` *applies* an approved settlement: credit application is still capped at the target purchase's `due_amount`, with any excess preserved on the `SupplierCredit` record. Raising the form-side value is safe because this overflow handling already exists.
- Purchase return creation and editing flows.
- The `PRODUCT_REPAIR` and `BROKEN_STOCK` settlement methods.

**Data**

- No schema changes and no data migration. Correction is achieved by recomputing at hydration, which repairs existing draft settlements when they are next opened.

**Behavioral risk**

- Draft settlement lines that operators have already reviewed may display a different `Nilai Penyelesaian` after this change, because the value now reflects the selected source purchase. This is the intended correction, but it is a visible change to in-flight work.
