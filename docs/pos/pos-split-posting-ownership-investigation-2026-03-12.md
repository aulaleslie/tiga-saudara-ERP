# POS Split Posting Ownership Investigation

Date: 2026-03-12  
Scope: investigation only (no implementation)

## Reported Scenario

POS checkout executed from `CV Tiga Nusa` with mixed cart ownership:
- Product A owned by `CV Tiga Nusa` (PKP = true)
- Product B owned by `CV Top IT` (PKP = false)
- Sale-location configuration allows selling Top IT stock from Tiga Nusa POS.

Expected checkout result:
- One POS checkout under Tiga Nusa.
- Two sales documents:
  - 1 sale under Tiga Nusa for Tiga Nusa-owned product(s)
  - 1 sale under Top IT for Top IT-owned product(s)
- Each sale uses its own business document numbering/prefix.

Actual result:
- Checkout splits into two groups, but both generated sales are still under Tiga Nusa.

## Runtime Verification

### Feature flag status

Runtime config confirms split posting is enabled:
- `config('pos.checkout.split_posting.enabled') = true`
- `env('POS_CHECKOUT_SPLIT_POSTING_ENABLED') = true`

Relevant config and binding:
- `Modules/Pos/Config/config.php:8`
- `Modules/Pos/Providers/PosServiceProvider.php:32-39`

### Database evidence from latest checkout (`pos_checkouts.id = 4`)

1. `pos_checkouts.split_summary.groups` is correct at planning/mapping level:
- Group 1: `split_key=1:1:TAX:1`, `source_setting_id=1`, `sale_id=2`
- Group 2: `split_key=2:2:NON_TAX`, `source_setting_id=2`, `sale_id=3`

2. `pos_checkout_sales` mapping is also correct:
- Row for `sale_id=2` mapped to `source_setting_id=1`
- Row for `sale_id=3` mapped to `source_setting_id=2`

3. But actual `sales` ownership is wrong:
- `sales.id=2` -> `setting_id=1`, `reference=TNC-JL-2026-03-00002`
- `sales.id=3` -> `setting_id=1`, `reference=TNC-JL-2026-03-00003`

4. Inventory transaction ownership also follows wrong setting for cross-owner line:
- Transaction for `location_id=2` (Top IT location) still has `setting_id=1`

Conclusion from evidence:
- Split planner and split mapping know the correct source owner.
- Final document creation still uses terminal POS setting for all groups.

## Root Cause

### Primary cause: group ownership not propagated into posting context

`SplitPosCheckoutPostingAdapter` iterates groups but reuses original checkout context when calling inline posting:
- `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php:62-79`

Specifically:
- `$groupContext = $context;`
- It rewrites grouped lines/totals, but **does not override** `setting_id` with `group['source_setting_id']`.
- Then calls `InlinePosCheckoutPostingAdapter->post($groupContext)`.

`InlinePosCheckoutPostingAdapter` uses `context['setting_id']` as owner source for all persistent docs:
- Customer lookup constrained by setting: `InlinePosCheckoutPostingAdapter.php:40-43`
- Sale created with `setting_id = $settingId`: `InlinePosCheckoutPostingAdapter.php:67-89`
- Product transaction created with `setting_id = $settingId`: `InlinePosCheckoutPostingAdapter.php:316-334`

Because `$settingId` stays terminal setting (Tiga Nusa), all split sales are persisted under Tiga Nusa.

### Why sale document number/prefix is wrong

`Sale` reference generation is setting-scoped:
- `Modules/Sale/Entities/Sale.php:85-112`

It filters latest reference by `setting_id` and builds prefix from that setting.
So when `sales.setting_id` is wrong, reference sequence/prefix is also wrong.

## Additional Constraint Discovered

Current inline posting requires customer to exist in the posting setting:
- `InlinePosCheckoutPostingAdapter.php:40-47`

In current DB state, only customer record found is:
- `customers.id=1`, `setting_id=1`

No customer row exists for setting `2` (Top IT) at investigation time.

Implication:
- A naive fix that simply switches `setting_id` per split group can fail with `CUSTOMER_UNRESOLVED` unless customer mapping policy is handled.

## Test Gap Analysis

Current split tests did not detect this because they do not assert per-sale ownership or cross-business source settings.

Evidence:
- `POSCheckoutSplitPostingTest` enables split mode and checks counts/totals only (`assertJsonCount`, `assertDatabaseCount`) at `Modules/Pos/Tests/Feature/POSCheckoutSplitPostingTest.php:80-99`.
- Test fixture uses one business setting with two locations (`createSplitCheckoutContext`), not two different setting owners: `POSCheckoutSplitPostingTest.php:133-151`.

As a result, ownership propagation defects pass existing tests.

## Recommended Fix Direction (for implementation phase)

1. Ownership propagation
- In split adapter, set per-group context owner before calling inline adapter:
  - `groupContext['setting_id'] = group['source_setting_id']`
  - Keep grouped lines/allocations unchanged.

2. Customer resolution policy for cross-owner sales
- Define deterministic policy when source setting differs from terminal setting:
  - Option A: use source setting walk-in customer per group.
  - Option B: map selected customer to source setting via shared identity key.
  - Option C: controlled fallback to source walk-in if selected customer not found in source.
- This must be explicit to avoid `CUSTOMER_UNRESOLVED` in valid split scenarios.

3. Document numbering
- Once `sales.setting_id` is corrected per group, sale reference prefix/sequence should naturally follow each owner setting via existing `Sale::boot()` logic.

4. Regression coverage additions
- Add feature test with at least 2 different source settings in one checkout and assert:
  - `split_groups[*].source_setting_id` matches `sales.setting_id` of corresponding `sale_id`
  - Each sale `reference` prefix belongs to its setting
  - `transactions.setting_id` matches source owner for each stock movement
  - Checkout still idempotent and totals reconcile.

## Investigation Conclusion

Split posting is active and split planning is correct, but ownership is lost at posting execution because split groups are posted with the original POS setting context. This causes:
- wrong `sales.setting_id`
- wrong sale document sequence/prefix
- wrong inventory transaction ownership

The issue is reproducible and confirmed with live checkout data.
