## Context

POS session cash is maintained as an event ledger plus a cached `pos_sessions.expected_cash_total`. Checkout finalization currently caps `CASH_SALE_IN` at the checkout grand total and also writes `CHANGE_OUT`, so change is deducted twice. The expected-cash calculator itself correctly sums IN events and subtracts OUT events; the defect is the checkout event payload and its matching cache update.

POS product, stock, serial, checkout planning, and returns resolve eligible locations through `SalesLocationResolver`, which caches enabled `setting_sale_locations` IDs for five minutes. The `Location` created hook bulk-inserts enabled assignments for every setting, bypasses assignment model events, and calls cache invalidation without setting IDs, which invalidates nothing. Both the standard controller and quick-add modal rely on this hook.

The change crosses `Modules/Pos`, `Modules/Setting`, and shared support code. It must retain checkout idempotency, cash-event auditability, multi-payment behavior, tenant boundaries, and SQLite-compatible focused tests.

## Goals / Non-Goals

**Goals:**

- Make checkout cash events describe physical drawer movements: actual cash tender enters the drawer and customer change leaves it.
- Keep the event-derived and cached expected-cash values equal after successful checkout finalization.
- Make new locations immediately POS-eligible for their owning business.
- Stop automatically enabling newly created locations for unrelated businesses.
- Make location creation and required sale-location onboarding atomic, idempotent, and testable across standard and quick-add flows.

**Non-Goals:**

- Rewriting closed or finalized session ledgers or changing the generic IN/OUT/NEUTRAL expected-cash calculator.
- Changing report definitions that already calculate net cash as tender minus change, except where needed to preserve reconciliation consistency.
- Changing barcode resolution, product stock ownership, terminal registration, or adding a terminal location selector.
- Removing the ability for one business to explicitly enable another business's location.
- Introducing new tables, external dependencies, or broad location-data backfills.

## Decisions

### Decision 1: Persist gross cash tender and separate change events

Checkout finalization will derive the cash-in amount from the validated payment payload: the single cash payment's tendered amount for cash-only checkout, or the sum of cash-method components for multi-payment checkout. `actualChangeTotal` remains a separate `CHANGE_OUT` event. The direct session cache update will add the same tender amount and subtract the same change amount written to the ledger.

This preserves a truthful audit trail and makes the invariant explicit:

```text
checkout drawer delta = cash tendered - change returned
expected cash = sum(IN events) - sum(OUT events)
```

Recording only the checkout grand total and removing `CHANGE_OUT` was rejected because it loses visibility into physical tender and change, weakens reconciliation, and conflicts with the existing change-event timeline. Recording net cash plus `CHANGE_OUT` is the current double-deduction defect.

### Decision 2: Use the validated checkout payment composition as the tender source

The finalizer will reuse its normalized, validated payment structure rather than re-querying sales payments after posting. For multi-payment, `total_cash_minor_units` is converted once to major currency units; for a single cash payment, the normalized paid total is the tender. Non-cash-only checkouts create no drawer events.

Re-querying persisted sale-payment splits was rejected because split-owner allocation represents accounting distribution, not necessarily the physical drawer interaction, and introduces rounding and relationship dependencies into cash-event creation.

### Decision 3: Preserve closed-session history and avoid an automatic corrective migration

No deployment migration will rewrite historical cash events or cached expected values. New finalizations use corrected semantics. Any investigation of legacy sessions will be read-only and will distinguish sessions whose `CASH_SALE_IN` equals checkout grand total while a positive `CHANGE_OUT` exists for the same checkout.

Automatic backfill was rejected because closed sessions may already have been counted, approved, exported, or manually reconciled. Mutating them would damage audit provenance. Open legacy sessions can be assessed operationally, but no automatic rewrite is part of this change.

### Decision 4: Assign a new location only to its owner inside the creation transaction

The location lifecycle will establish one enabled `SettingSaleLocation` for `location.setting_id`, using idempotent create-or-update behavior. Standard and quick-add entry points will execute location creation within a database transaction so assignment failure rolls back the location. The model-level invariant remains the common path for current creation surfaces.

Bulk-enabling the location for every setting was rejected because it crosses tenant boundaries by default and makes a local location-management action alter every business's POS eligibility. Moving all assignment responsibility into each controller was rejected because future creation paths could omit the invariant.

### Decision 5: Invalidate explicit affected cache keys

After an assignment mutation, cache invalidation will receive the concrete setting IDs affected by that mutation. Location creation will invalidate the owning setting. Existing assignment model hooks continue invalidating old and new settings for enablement, ownership changes, reorder, and deletion.

Calling a global no-argument invalidation was rejected because the current helper intentionally operates on supplied IDs and the call is a no-op. Flushing the entire application cache was rejected as unnecessarily broad and disruptive.

### Decision 6: Verify behavior at finalization and resolver boundaries

Focused feature tests will assert both persisted events and the cached/recalculated session value for cash-only, mixed, exact, overpayment, non-cash, and idempotent replay cases. Location tests will warm the resolver cache before standard and quick-add creation, then assert immediate owning-setting visibility and absence from unrelated settings.

Testing only the calculator was rejected because it already behaves correctly with a correct ledger. Testing only assignment rows would miss the stale-cache failure reported by users.

## Risks / Trade-offs

- **[Risk] Existing tests encode the incorrect grand-total-minus-change expectation.** → Update those assertions to the physical tender/change invariant and add the reported Rp5,780,000 regression case.
- **[Risk] Mixed-payment normalization may expose minor-unit rounding errors.** → Convert validated cash minor units once, round to currency precision, and assert event/cache equality.
- **[Risk] Open sessions created before deployment may contain legacy events alongside corrected events.** → Do not rewrite automatically; make any diagnostic distinguish per-checkout legacy patterns and communicate the boundary operationally.
- **[Risk] Code paths using mass database inserts bypass Eloquent cache hooks.** → Keep cache invalidation explicit at every bulk assignment mutation and cover the shared resolver in integration tests.
- **[Risk] Changing global location auto-enablement may reveal workflows that implicitly relied on it.** → Preserve explicit cross-business enablement and test the configuration toggle path.
- **[Risk] Model-event transactions can be obscured when callers are not transactional.** → Wrap both known user-facing creation flows and verify rollback when required assignment persistence fails.

## Migration Plan

1. Deploy the checkout finalization and focused regression tests without schema changes.
2. Deploy owning-business location assignment and explicit cache invalidation with standard, quick-add, and cross-business configuration tests.
3. Run focused POS checkout/session tests and Setting sale-location tests, followed by the project SQLite verification command if practical.
4. Smoke-test a checkout with cash change and confirm ledger events, cached expected cash, and recalculated summary agree.
5. Warm a business's sale-location cache, create a location, and confirm immediate POS resolution for the owner and exclusion from an unrelated business.

Rollback restores prior application behavior without reversing schema or historical data. Location assignments created under the corrected owner-only rule remain valid; rollback MUST NOT synthesize cross-business assignments. Cash events posted after deployment remain truthful audit records and MUST NOT be rewritten during rollback.

## Open Questions

None. Closed-session history remains immutable, new locations default to the owning business only, and cross-business use remains an explicit configuration action.
