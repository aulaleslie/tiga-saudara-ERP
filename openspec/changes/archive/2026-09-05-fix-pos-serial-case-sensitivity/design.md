## Context

`product_serial_numbers.serial_number` is a `varchar(255)` column with `utf8mb4_unicode_ci` collation — MySQL treats `=`/`whereIn` comparisons on it as case-insensitive, and the unique key `(product_id, serial_number)` already prevents true case-duplicates for the same product. Verified against a production snapshot: all 299 existing rows are already uppercase, no cross-product case collisions exist, and `dispatch_details.serial_numbers` (JSON) contains zero lowercase entries — so corruption has never reached a finalized posting.

The bug is entirely in the PHP layer, downstream of a correct SQL fetch. The common shape is:

```php
$rows = ProductSerialNumber::query()->whereIn('serial_number', $input)->get()->keyBy('serial_number');
$record = $rows->get($someInputString); // byte-exact PHP array-key lookup
```

`keyBy('serial_number')` keys the collection by the DB's canonical-case value; `get()`/`isset()` against a differently-cased input string misses even though the underlying rows exist. The same shape recurs as `in_array($sn, $arr, true)`, `array_search($sn, $arr, true)`, `array_diff($input, $existing)`, and `array_intersect($a, $b)` wherever one side is user/cart-supplied text and the other is DB-fetched text.

Two production POS carts (`#2779`, `#3250`) are currently stuck this way: a serial was assigned in lowercase and now fails checkout's stock validation, reporting the item as unavailable even though the physical unit and its DB row are active and unassigned.

`Modules/Sale/Http/Controllers/SaleController.php` and `Modules/Sale/Services/SaleSerialDisplayResolver.php` already independently solved this correctly, each with their own private `mb_strtoupper(trim($value), 'UTF-8')` helper before building a lookup key. That pattern is proven; it just needs to be centralized and applied everywhere else it's missing.

## Goals / Non-Goals

**Goals:**
- Every serial-number comparison in the codebase becomes case-insensitive, regardless of which side (input vs. DB-fetched) holds which case.
- Existing case-mismatched POS carts (`#2779`, `#3250`) become checkout-able the moment the fix ships, with no data migration.
- One canonical normalization function, reused everywhere, replacing the two existing ad-hoc reimplementations.
- All new writes to `product_serial_numbers.serial_number` are normalized at the model layer, so future case drift cannot be introduced by any write path (POS, Purchase, Consignment, PurchasesReturn, admin CRUD).

**Non-Goals:**
- No backfill/migration of `product_serial_numbers` data — verified unnecessary.
- No new unique constraint — the existing `(product_id, serial_number)` key under `utf8mb4_unicode_ci` already enforces case-insensitive uniqueness per product.
- No change to serial number format, generation, or business rules beyond casing.
- No UI changes beyond what naturally falls out of fixing the underlying comparisons (no new client-side uppercasing is required, since the fix is comparison-side and self-healing).

## Decisions

**1. Normalize via `mb_strtoupper(trim($value), 'UTF-8')`, centralized as `ProductSerialNumber::normalize()`.**
This is the exact function already proven correct in `SaleSerialDisplayResolver`/`SaleController`. Using `mb_strtoupper` (not `strtoupper`) preserves correctness for any non-ASCII characters that might appear in a serial. Centralizing it as a static method on `ProductSerialNumber` keeps the codebase's existing idiom (the model already normalizes `status` via `getStatusAttribute`) and gives every consumer one import instead of three private reimplementations.
- *Alternative considered*: SQL-side `LOWER()`/`UPPER()` wrapping (the pattern used for `barcode`). Rejected because serial number is a controlled-format identity key with a single canonical case, unlike `barcode`'s uncontrolled historical data — wrapping every comparison in SQL functions would also defeat plain B-tree index usage without adding a functional index, and would not fix the many pure-PHP (`in_array`, `array_diff`, `keyBy`) sites, which are the actual failure points.

**2. Add a `setSerialNumberAttribute` mutator on `ProductSerialNumber` so every future write is normalized at the model layer.**
This closes the write side once, at the point of least duplication, rather than requiring every one of the ~10+ creation call sites (Purchase, Consignment, PurchasesReturn, admin CRUD, serial conversion) to remember to normalize.
- *Alternative considered*: normalize only at each controller's validation layer. Rejected — easy to miss on a new call site; a model mutator is unconditional.

**3. Fix comparisons in place (normalize both sides at the point of lookup), not by migrating stored data.**
Because production data is already 100% canonical-case, the only mismatches live in transient cart/session state (POS `assigned_serials` arrays) and ad hoc request input (return/adjustment forms). Fixing the comparison, not the data, makes already-broken in-flight carts recoverable without any UPDATE statement — directly satisfying the requirement that stuck transactions unblock without a data fix.

**4. Where a comparison's result feeds forward (e.g. `ResolvePosStockAllocationsService`'s allocation output, later read by `PosCheckoutSplitPlannerService`/`InlinePosCheckoutPostingAdapter`), propagate the DB-canonical value, not the raw input.**
Once a serial is matched to its `ProductSerialNumber` record, all downstream uses (dispatch JSON, `PendingDispatchSerialGuard::isReserved()`, stock movement history) should use `$record->serial_number` (canonical) rather than the original possibly-miscased input string. This prevents the same class of mismatch from being reintroduced one step further downstream (e.g. `PendingDispatchSerialGuard`'s JSON `LIKE` match, which is a separate raw-string comparison against `dispatch_details.serial_numbers`).

## Sites in scope

Grouped by module; each requires normalizing both sides of the comparison (or propagating the canonical value forward per Decision 4):

- **POS checkout**: `ResolvePosStockAllocationsService.php` (`keyBy`/`get`, plus the `PendingDispatchSerialGuard::isReserved()` call and the `serial_numbers` written into the allocation), `PosCheckoutSplitPlannerService.php` (`keyBy`/`get`), `Adapters/InlinePosCheckoutPostingAdapter.php` (two `keyBy`/`isset` sites — parent and bundle component — plus the `array_intersect` case-sensitivity gap).
- **POS cart**: `PosCartService.php` — `assignSerialsWithinLock()` (normalize input before validating/storing, both parent-line and bundle-component branches), `collectCartWideAssignedSerials()` (normalize when reading back), the `in_array(..., true)` duplicate checks, and the `array_search(..., true)` removal lookups.
- **POS scan**: `PosScanResolverService.php` — normalize the scanned/typed query before the exact serial match.
- **SalesReturn**: `app/Livewire/SalesReturn/Concerns/ValidatesSaleReturnForm.php` — normalize the form's submitted serial list before `whereIn`/`keyBy`/`get`.
- **PurchaseReturn**: `app/Livewire/PurchaseReturn/Concerns/ValidatesPurchaseReturnForm.php` — normalize before `whereIn`/`array_diff`.
- **Consignment**: `ConsignmentSoldSourceDiscoveryService.php` (`keyBy`/`array_diff`), `ConsignmentBillingPreviewService.php` (`in_array(..., true)`).
- **Sale (refactor only, no behavior change)**: `SaleController::saleSerialLookupKey()` and `SaleSerialDisplayResolver::normalizeSerialValue()` — replace their private implementations with calls to `ProductSerialNumber::normalize()`.
- **Model**: `ProductSerialNumber.php` — add `normalize()` static helper and `setSerialNumberAttribute` mutator.

Confirmed **not** in scope: `PurchaseReturnDispatchController.php:351` and `AdjustmentController.php:718` — both diff numeric serial IDs, not serial-number strings.

## Risks / Trade-offs

- **[Risk] Missing a site reintroduces the exact bug in that one flow.** → Mitigation: the site list above was produced by an exhaustive codebase-wide search (not just POS) cross-checked against a subagent's independent inventory; tasks.md enumerates every site individually so none can be silently skipped.
- **[Risk] Changing `keyBy()`/lookup keys near `lockForUpdate()` queries could be mistaken for a query-structure change and alter locking behavior.** → Mitigation: the fix only changes the closure passed to `keyBy()` and the key used in the subsequent `get()`/`isset()` — the underlying query (including `lockForUpdate()`, `whereIn()`, ordering) is untouched, so row locks are acquired identically. Call this out explicitly in the corresponding task so a reviewer can verify the query itself is unchanged.
- **[Risk] Centralizing two already-correct implementations (`SaleController`, `SaleSerialDisplayResolver`) into a shared helper could subtly change behavior if `mb_strtoupper(..., 'UTF-8')` isn't byte-identical to the new shared function.** → Mitigation: copy the exact existing implementation verbatim into `ProductSerialNumber::normalize()`; add a focused unit test asserting the two former call sites' outputs are unchanged for representative inputs (mixed case, leading/trailing whitespace, multi-byte characters if any).
- **[Risk] `setSerialNumberAttribute` mutator affects every write path simultaneously.** → Mitigation: production data is already fully normalized, so no existing row's stored value changes on next save; only the case of any future non-canonical input changes, which is the intended fix.
- **Trade-off**: normalizing in PHP rather than SQL means every call site must explicitly opt in by calling `normalize()` at the right point, rather than the DB doing it for free. Accepted because most of the failures are pure-PHP comparisons (`in_array`, `array_diff`, `keyBy`) that SQL-side normalization would not fix anyway.

## Migration Plan

No data migration. Deployment is a straightforward code release:
1. Ship the `ProductSerialNumber` model change (normalize helper + mutator) and all comparison-site fixes together in one deploy — partial deployment could leave some sites normalized and others not, reintroducing inconsistency mid-rollout.
2. No feature flag needed — this is strictly a bug fix with no behavior change for already-matching-case input.
3. Rollback: revert the deploy; no data was altered, so rollback is safe and immediate.
4. Post-deploy verification: confirm POS transactions `#2779` and `#3250` can complete checkout (human browser/eye test, per user instruction — no automated E2E required for this).

## Open Questions

None outstanding — scope, data state, and fix pattern are all verified against the live codebase and production data snapshot.
