## Context

The `PosSessionLifecycleService.openSession()` method at lines 117-131 validates opening float (Saldo Awal) against `terminal.policy.cash_threshold`. This validation was intentionally removed in commit 0f7c215 (2026-03-16) but accidentally re-introduced in commit 9172c74 (2026-03-19) as a side-effect of message localization refactoring. The current code blocks session creation if:
- `require_opening_float` flag is true, AND
- Opening float ≤ terminal policy's `cash_threshold`

This is semantically incorrect because `cash_threshold` serves as a monitoring alert threshold (when to notify that a terminal holds too much cash), not as a minimum opening balance requirement. The `cash_threshold` is independently used by `PosSessionSummaryService`, `PosSessionMonitorService`, and `PosSafeDropService` for threshold-aware calculations and visibility—it should not gate session creation.

## Goals / Non-Goals

**Goals:**
- Remove the threshold validation check from `PosSessionLifecycleService.openSession()` (lines 117-131)
- Allow sessions to open with any positive opening float when a terminal is selected, without checking against `cash_threshold`
- Preserve `cash_threshold` in terminal policy for downstream monitoring and calculation services
- Ensure all existing uses of `cash_threshold` (summary, monitor, safe drop) remain functional and unmodified

**Non-Goals:**
- Change terminal policy schema or migration
- Modify the finalize flow or variance approval logic
- Alter request layer validation (opening_float_total > 0 check remains at form/request level)
- Change behavior of `PosSessionSummaryService`, `PosSessionMonitorService`, or `PosSafeDropService`

## Decisions

### 1. Delete Lines 117-131 from PosSessionLifecycleService.openSession()

**Decision:** Remove the entire threshold validation block:
```php
if ((bool) ($terminalPolicy?->require_opening_float ?? false)) {
    $thresholdValue = $terminalPolicy?->cash_threshold;

    if ($thresholdValue === null) {
        throw new DomainException('Terminal cash threshold must be configured...');
    }

    if ($openingTotal <= (float) $thresholdValue) {
        throw new DomainException('Opening float total must be greater than terminal cash threshold.');
    }
}
```

**Rationale:**
- This is the only place `cash_threshold` is used as a validation gate
- The `require_opening_float` flag's purpose is to mandate opening float input, not to validate against a threshold
- The > 0 check (lines 80-82) already enforces that opening float is positive when a terminal is selected
- No downstream code depends on this validation happening at open time; monitoring services independently check and flag threshold violations

**Alternatives Considered:**
- Keep validation but make it optional: Adds complexity without solving the semantic issue
- Move validation to request layer: Doesn't fix the problem—same gating logic, different location
- Deprecate cash_threshold field: Unnecessary—it's still useful for monitoring; we just stop using it for gating

### 2. Update or Remove Conflicting Tests

**Decision:** Address tests that enforce the old behavior:
- `POSOpeningFloatCaptureTest::test_it_rejects_when_opening_float_total_is_less_than_or_equal_to_cash_threshold()` (line 136) expects rejection—this test becomes invalid
- `POSOpeningFloatCaptureTest::test_it_rejects_when_cash_threshold_is_null()` (line 157) expects rejection when threshold is missing—this test becomes invalid
- `POSSessionLifecycleTest::test_open_session_allows_float_below_cash_threshold()` (line 187) expects success—this test is already correct and should continue to pass

**Rationale:**
- Tests 136 and 157 were written to enforce threshold validation; that behavior is no longer desired
- Test 187 was added in the fix commit (0f7c215) that removed the validation; it validates the correct behavior
- Removing outdated tests prevents misleading assertions about the API contract

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **User confusion**: Cashiers may open sessions with very small floats, unaware of policy limits | Stakeholder visibility via monitoring dashboard (existing feature in PosSessionMonitorService). Help text during session open can clarify policy expectations. |
| **Undetected policy violations**: Sessions that should be flagged (below policy) are not flagged at open time | Acceptable—monitoring services still flag them in real-time. Open-time validation was too strict for a monitoring threshold. |

## Migration Plan

1. **Code change** (low risk, no data migration):
   - Edit `Modules/Pos/Services/PosSessionLifecycleService.php`, delete lines 117-131

2. **Test cleanup**:
   - Remove or mark as xfail `POSOpeningFloatCaptureTest::test_it_rejects_when_opening_float_total_is_less_than_or_equal_to_cash_threshold()`
   - Remove or mark as xfail `POSOpeningFloatCaptureTest::test_it_rejects_when_cash_threshold_is_null()`
   - Verify `POSSessionLifecycleTest::test_open_session_allows_float_below_cash_threshold()` continues to pass

3. **Verification**:
   - Run full test suite for POS module
   - Verify session opening works with opening float < terminal threshold
   - Verify monitoring services still calculate thresholds correctly

4. **Deployment**:
   - Code-only change; no database migrations or config changes
   - Can be deployed independently
   - No backward compatibility concerns (internal API only)

5. **Rollback**:
   - Revert the service file and test changes
   - No data cleanup needed

## Open Questions

None. Implementation is straightforward.
