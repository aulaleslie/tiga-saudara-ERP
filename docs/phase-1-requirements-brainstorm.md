# Phase 1 — Requirements Brainstorm

Date: 2026-02-08  
Context anchor: `http://localhost:8000/purchases/3/receive`

## Problem Statement (Rephrased)
When receiving purchase items, a serial number that was previously returned to supplier (`status = RETURNED`) is currently blocked with message `Serial number sudah ada untuk produk ini.`  

Observed on current local data (via Tinker):
1. Purchase `id=3` includes serial-tracked product `id=2`.
2. Serial `202602080001` exists for product `2` with status `RETURNED`.
3. Current validation behavior shows:
4. `existsCommitted_currentQuery = true`.
5. `existsActive_ifFiltered = false`.
6. `existsReturned = true`.

So the system treats all existing records as duplicates, instead of allowing legitimate serial reuse for returned serial lifecycle.

## Clarifying Questions (No Limit, High-Impact)
1. Should `RETURNED` serials always be valid for reuse during purchase receiving? (Yes)
2. Is `RETURNED` the only reusable status, or should any other status be reusable? (Yes)
3. Must `RETURN_IN_PROCESS` remain blocked for receiving reuse? (Yes)
4. Must `BROKEN` remain blocked for receiving reuse? (Yes, broken means we maintain the ownership)
5. Should reuse be allowed only when `is_in_return_process = false`? (Yes)
6. Should reuse require `purchase_return_id` to be present, or status check alone is enough? (Status check alone is enough)
7. On reuse, should we reactivate the existing row (`update`) or create a new row (`insert`)? (Reactivate existing row)
8. If reactivating existing row, should we overwrite `received_note_detail_id` with the new receiving detail? (Yes)
9. If reactivating existing row, should we clear `purchase_return_id`? (Yes)
10. Should `location_id` be updated to the receiving location on reuse? (Yes)
11. Should `tax_id` follow the new purchase line tax or preserve prior serial tax? (Follow the new purchase line tax)
12. Should reuse be allowed across different suppliers, or only same supplier as original purchase return? (allowed across different suppliers)
13. Should reuse be allowed across different purchases/POs in the same setting? (Yes)
14. Do we need setting-level guardrails beyond `product_id` scoping for reuse checks? (No)
15. Should frontend validation message remain generic, or explicitly say serial is reusable because it is returned? (Just notify user that serial number was used before)
16. Should we show informational warning (not error) when reusing a returned serial? (Yes)
17. Should duplicate check in `storeReceive` block only non-reusable statuses (`ACTIVE`, `RETURN_IN_PROCESS`, maybe `BROKEN`)? (Yes)
18. Should pending receiving checks remain unchanged (still always block)? (I'm not sure about this one)
19. What should happen if two pending receivings concurrently try to reuse the same returned serial? (the later one will be blocked)
20. Is DB-level concurrency handling required now (locks/transactional re-check) or acceptable as follow-up? (I'm not sure about this one)
21. Should serial history append `RECEIVED` on reuse, or use a separate event type (e.g., `RECEIVED_AGAIN`)? (`RECEIVED`)
22. Should old purchase serial visibility behavior remain unchanged after reuse? (Yes, in old purchase it stays visible. in new purchase, the color will be the same as newly received serial.)
23. Should this reuse rule also be applied to `serial-numbers/update` endpoint (manual serial edit checks)? (No, serial numbers update will rarely be used. but let it keep only prevent duplicate input)
24. Should this reuse rule be applied to any import flows that may create serials? (No)
25. Should we include regression coverage for both controller endpoint and full purchase receiving approval path? (Yes)
26. Should module tests under `Modules/*/Tests` be included in your standard CI command for this change? (Yes)

## Solution Approaches

### Approach 1 — Status-Aware Reuse In Existing Flow (Targeted Fix)
Change duplicate checks to treat `RETURNED` as reusable, then handle reuse at approval by reactivating existing serial row instead of inserting duplicate.

Pros:
1. Smallest change set aligned with current architecture.
2. Respects existing unique index (`product_id + serial_number`) without schema changes.
3. Fastest path to unblock receiving scenario.
4. Keeps history model intact by adding new lifecycle event entries.

Cons:
1. Logic remains distributed across controller methods.
2. Potential behavior drift with other serial entry points unless explicitly aligned.

Trade-offs:
1. Speed and low risk vs less centralization.
2. Good for hotfix + tests-first delivery.

### Approach 2 — Centralized Serial Availability Policy Service
Extract serial availability and reuse policy into a dedicated service used by `validateSerial`, `storeReceive`, and approval commit logic.

Pros:
1. Single source of truth for status semantics.
2. Reduces future inconsistency across endpoints/Livewire/controller flows.
3. Easier to extend with additional statuses/business rules later.

Cons:
1. Larger scope and more touching points than urgent fix.
2. Requires broader regression testing and review.

Trade-offs:
1. Higher initial cost for better long-term maintainability.
2. Better if you expect more serial lifecycle rule changes soon.

### Approach 3 — Immutable Serial Versioning Model
Keep old serial rows immutable and create versioned ownership records (schema and flow redesign).

Pros:
1. Clean audit story for every lifecycle transition.
2. Clear separation between historical and active ownership.

Cons:
1. High complexity and migration impact.
2. Requires major refactor of relationships and many queries.
3. Not suitable for current bugfix urgency.

Trade-offs:
1. Strong model purity vs high delivery risk/time.

## Recommended Approach
Recommend **Approach 1** now, with optional light internal refactor from Approach 2 if time allows.

Reasoning:
1. The reported issue is immediate and reproducible in current flow.
2. Current schema already expects no duplicate serial rows per product, so reactivation is the natural fit.
3. This minimizes blast radius while still producing deterministic behavior.
4. It aligns with existing purchase return reuse expectations already visible in tests (returned serials can be reactivated in settlement flows).

## Open Decisions / Assumptions
1. Assumption: `RETURNED` must be reusable during purchase receiving.
2. Assumption: `ACTIVE` and `RETURN_IN_PROCESS` remain blocked.
3. Assumption: Reuse should reactivate existing serial row (not insert new row).
4. Assumption: Reuse updates `location_id`, clears `purchase_return_id`, clears `is_in_return_process`.
5. Assumption: Reuse appends a new `RECEIVED` history record.
6. Assumption: Pending receiving duplicate checks remain strict.
7. Open decision: whether to preserve or overwrite `tax_id` on reuse.
8. Open decision: whether to adjust frontend message for returned-serial reuse.
9. Open decision: whether to include cross-endpoint harmonization now or in follow-up.
10. Open decision: whether to expand CI command to include `Modules/*/Tests` by default.
