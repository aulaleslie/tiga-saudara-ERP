## Context

The cross-business price page renders one row per `Setting` and submits all rows through `CrossBusinessPriceService`, which already validates the complete setting set and saves atomically with optimistic locking. Its four editable columns use `jquery-mask-money`; average purchase price is intentionally read-only.

Ordinary receiving approval currently updates the active purchase setting's `ProductPrice.last_purchase_price`, then calculates that setting's new average and uses `ProductAveragePriceSynchronizer` to copy the average across all settings. Purchase imports already copy last purchase price across all settings. This leaves normal receiving as the remaining path that can create cross-business last-price drift.

## Goals / Non-Goals

**Goals:**

- Make a changed editable price easy to copy to the same column across every displayed business.
- Preserve the page's explicit edit, save, cancel, authorization, validation, and optimistic-locking behavior.
- Make ordinary approved receiving synchronize last purchase price across all current settings.
- Create missing product-price rows while preserving unrelated fields on existing rows.
- Verify the change with only focused Product and Purchase tests, supplemented by review when browser JavaScript execution is unavailable.

**Non-Goals:**

- Making average purchase price manually editable or copyable.
- Automatically saving when a column is copied.
- Adding a server endpoint dedicated to column copying.
- Changing purchase cost calculation, receiving eligibility, lifecycle rules, or legacy `products.last_purchase_price` behavior.
- Backfilling historical price rows or changing purchase import behavior.
- Running the full application test suite.

## Decisions

### Decision 1: Perform column propagation in the existing form

Each editable input will carry a stable price-column identifier and have an adjacent button. Existing JavaScript will compare normalized, unmasked numeric values on initialization and input/change events, toggle the corresponding button, and copy a clicked source value to inputs sharing that column identifier. After copying, the mask will be refreshed and change visibility recalculated for all affected inputs.

This keeps copying as an unsaved form convenience and lets the existing single PUT request remain the persistence boundary. A dedicated backend endpoint was considered but rejected because it would introduce partial-save semantics and duplicate the current authorization, locking, and transaction workflow.

### Decision 2: Treat each field's loaded server value as its baseline

The existing `data-original` value remains the baseline for Cancel and dirty detection. Comparisons will normalize masked strings to numeric values so display separators do not create false changes. When validation returns old input, the server-loaded current value remains the original baseline, allowing previously submitted differences to be visibly recognized as unsaved changes.

Comparing display strings was rejected because `100000` and `100.000` are semantically equal under the page's zero-precision currency mask.

### Decision 3: Keep a control beside every editable row rather than one header action

The source row matters because the requested value originates from a particular changed field. A per-field control makes that source explicit and satisfies the rule that the action appears only after that field changes. A header-level action was considered but rejected because it would require separate source selection and would be visible without a changed source value.

### Decision 4: Encapsulate global last-price writing in a Product service

A focused synchronizer will accept a product ID and last purchase price, obtain all current setting IDs, and use the existing idempotent `ProductPrice::seedForSettings` behavior to update or create one row per setting. Supplying only `last_purchase_price` preserves unrelated attributes on existing rows and relies on table defaults for newly created rows.

Writing an inline loop in `PurchaseController` was considered but rejected because purchase import and average-price code already establish synchronization as reusable Product-domain behavior.

### Decision 5: Invoke synchronization inside the existing approval transaction

For every receiving detail with a positive accepted quantity, approval will synchronize the exact value currently used as last purchase price, `purchaseDetail->price`. The legacy product-level update remains unchanged. The global write occurs within the existing transaction so stock, transaction logs, active-setting averages, and cross-business price snapshots commit or roll back together.

Recalculating last purchase price from DPP was rejected because it would change established receiving semantics beyond the requested propagation. Timestamp-based historical arbitration was also rejected: the current runtime rule is that the successfully approved receiving event wins.

## Risks / Trade-offs

- [Risk] Copying a value can change many rows unintentionally. → Mitigation: copying remains unsaved, affects only one named column, and Cancel restores all original values.
- [Risk] Mask formatting can make dirty detection inconsistent. → Mitigation: compare normalized numeric values and recalculate state after masking, manual edits, copy, and cancel.
- [Risk] Creating a missing `product_prices` row could populate unrelated fields incorrectly. → Mitigation: use the existing partial `seedForSettings` helper and assert default/preserved fields in focused tests.
- [Risk] More settings increase writes during receiving approval. → Mitigation: the number of settings is small reference data, and writes occur only for positively received products inside the already-required transaction.
- [Risk] Current feature tests may inspect rendered JavaScript without executing it. → Mitigation: test server-rendered hooks and receiving behavior automatically, then review the small interaction code against the scenarios if no browser harness is available.

## Migration Plan

Deploy the Blade/JavaScript and service/controller changes together. No database migration or historical backfill is required. Existing rows converge when products are next received or when users explicitly copy and save a column.

Rollback is a code rollback. Values already intentionally saved or synchronized during the deployed window are not automatically reverted.

## Open Questions

None.
