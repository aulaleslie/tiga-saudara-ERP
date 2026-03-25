## Context

Currently, reporting methods in `PosReportingService` and `PosReconciliationService` aggregate cash payments from the `pos_checkout_payments` table without accounting for change. When a customer overpays (e.g., 50,000 cash + 30,000 QRIS for a 75,000 transaction), they receive 5,000 change. The system correctly tracks this change in `pos_checkouts.change_total` and records it as a `DIRECTION_OUT` event in `pos_session_cash_events`, but reports show the full 50,000 cash payment instead of the actual 45,000 received.

**Data Model:**
- `pos_checkouts`: master transaction record with `change_total` field
- `pos_checkout_payments`: individual payment entries (multi-payment support), one row per payment method per checkout
- `payment_methods`: contains `is_cash` flag
- `pos_session_cash_events`: tracks cash inflows (CASH_SALE_IN) and outflows (CHANGE_OUT)

**Current Implementation:**
- Change calculation is correct in `FinalizePosCheckoutService` (lines 120-127, 609-616)
- Change is persisted to `pos_checkouts.change_total`
- Cash events correctly record CASH_SALE_IN and CHANGE_OUT
- But reporting queries ignore `change_total` field entirely

## Goals / Non-Goals

**Goals:**
1. Correct all three affected reporting methods to deduct change from cash totals
2. Ensure reports match cash session events (CASH_SALE_IN - CHANGE_OUT = actual cash in drawer)
3. Fix without schema changes (use existing fields only)
4. Maintain backward compatibility (no API response structure changes, only aggregated values)

**Non-Goals:**
- Changing how change is calculated or stored (existing logic is correct)
- Modifying cash event tracking (already accurate)
- Creating new database tables or fields
- Changing payment method summary or item sales reporting (unaffected by change)

## Decisions

### Decision 1: Deduct Change at Aggregation Level (vs. Session Event Approach)

**Chosen:** Deduct `change_total` directly in each reporting query using SQL aggregation

**Rationale:**
- Keeps logic close to existing queries (minimal change surface area)
- Uses the canonical `change_total` stored in `pos_checkouts`
- All three affected methods can follow the same pattern
- Single source of truth (not dependent on event types or ordering)
- No performance overhead

**Alternative Considered:** Query `pos_session_cash_events` table and calculate from EVENT_CASH_SALE_IN and EVENT_CHANGE_OUT
- Pros: "truth from drawer perspective"
- Cons: More complex joins, depends on event creation logic, harder to correlate individual checkouts to reports

**Decision:** Use direct subtraction approach.

### Decision 2: Handle Change Allocation in Multi-Payment Scenarios

**Chosen:** Deduct `change_total` once per checkout from the cash payment total, not per payment method

**Rationale:**
- Change is calculated per-checkout (not per-payment-method)
- In multi-payment, change comes from the cash component only (see finalization logic: `hasCash` condition)
- If 50K cash + 30K QRIS = 80K paid for 75K transaction: change is 5K, deducted from the 50K
- Report should show: cash_total = 50K - 5K = 45K (not 30K or some other split)

**Edge Case Handling:**
- Single cash payment: subtract full change_total
- Multiple payment methods but only one is cash: subtract change_total from that cash payment
- All non-cash payments: no change deduction (change_total would be 0 anyway)
- Split groups: change_total applies globally to checkout, deduction done once per checkout (not per group)

**Implementation:** Use subquery that selects the maximum of (change_total, 0) and subtracts it once per checkout in the aggregate

### Decision 3: Query Pattern for Change Subtraction

**Chosen:** Correlated subquery that calculates change per checkout, then subtracts in the main aggregate

**Pattern:**
```sql
SELECT SUM(pcp.amount_minor_units / 100) -
       SUM(COALESCE((
           SELECT pc.change_total
           FROM pos_checkouts pc
           WHERE pc.id = pcp.pos_checkout_id
           LIMIT 1
       ), 0))
FROM pos_checkout_payments pcp
WHERE pm.is_cash = 1
```

**Why this pattern:**
- Guarantees change_total subtracted once per checkout (not repeated per payment)
- Works with existing joins and WHERE conditions
- Clear intent and easy to debug
- Minimal risk of double-counting change across groups

**Alternative Considered:** JOIN to subquery of distinct checkout/change combinations
- More complex but potentially clearer intent
- Risk of multiple rows if not careful with grouping

**Decision:** Use correlated subquery for clarity and safety.

### Decision 4: Scope of Changes

**Chosen:** Fix three specific methods in two files; leave getPaymentMethodSummary unchanged

**Methods to Fix:**
1. `PosReportingService::getDailySalesSummary()` - lines 26-33
2. `PosReportingService::getCashierSummary()` - lines 78-84
3. `PosReconciliationService::getSessionReconciliation()` - lines 38-44

**Methods NOT Changed:**
- `getPaymentMethodSummary()` - aggregates by payment method, not affected by change deduction
- `getItemSalesSummary()` - aggregates by product, not affected

**Rationale:**
- These three are specifically cash-total calculations
- Payment method summary and item sales operate at different aggregation levels
- Minimal blast radius reduces risk

## Risks / Trade-offs

**[Risk] Subquery Performance on Large Datasets**
→ Mitigation: Subquery is simple and correlates on indexed `pos_checkouts.id`. Test with realistic data volume. If performance is poor, can be optimized with a pre-calculated view.

**[Risk] Change Deduction Logic in Multiple Places**
→ Mitigation: All three methods use identical pattern (correlated subquery). Document clearly. Consider extracting to helper if more places need it in future.

**[Risk] Test Failures in POSReportingPackTest**
→ Mitigation: Tests create checkouts with single payment (no change case), so need to be updated or new test cases added for multi-payment with change scenarios. This is expected and necessary.

**[Risk] Misunderstanding of When Change Applies**
→ Mitigation: Only applies when `hasCash = true`, which matches finalization logic. Validate assumption in tests with all non-cash payments.

**[Trade-off] Query Complexity vs. Alternative Approaches**
- This approach: Correlated subquery (one additional subquery per report)
- Alternative: Redesign data model (major change, out of scope)
- Decision: Accept slightly more complex queries for minimal code changes

## Migration Plan

**Deployment:**
1. Update all three query methods in code
2. Run existing test suite (expect failures in POSReportingPackTest)
3. Update test helper `createCheckout()` or add multi-payment test cases
4. Verify reports show lower cash totals for checkouts with change
5. Compare against session events to validate correctness
6. Deploy to staging, validate reconciliation reports match drawer events
7. Deploy to production

**Rollback:** Simple code revert (query logic only, no data migration needed)

**Testing Strategy:**
- Unit: Update POSReportingPackTest cases
- Integration: Create test data with multi-payment + change, validate report aggregations
- Manual: Run reports in staging, cross-check against session cash events

## Open Questions

1. Should we create a helper method to encapsulate change deduction logic, or keep it inline in three places?
   → Recommend: Inline first for visibility, extract later if pattern repeats
2. Do we need to update dashboard/UI to label cash totals more clearly (e.g., "Actual Cash Received")?
   → Out of scope for this change, but good future enhancement
3. Are there other reporting methods in other modules that might have the same issue?
   → Out of scope; focus on POS module first
