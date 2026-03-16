## Context

The `PosSessionLifecycleService.openSession()` method currently validates opening float against `terminal.policy.cash_threshold` at lines 114-120. This validation was added to enforce a "minimum opening balance" constraint, but the `cash_threshold` field is semantically a monitoring/informational threshold—it indicates the policy limit for cash holdings and is used by:

- `PosSessionSummaryService` (line 49) — calculates threshold for summary responses
- `PosSessionMonitorService` (line 56) — monitors sessions exceeding threshold
- `PosSafeDropService` (line 165) — calculates expected cash against threshold

The validation gate prevents sessions from opening with valid (positive) opening floats that happen to be below the policy threshold, blocking valid workflows. Per the proposal, the threshold should inform stakeholders (via monitoring), not gate session creation.

## Goals / Non-Goals

**Goals:**
- Remove the threshold validation check from `PosSessionLifecycleService.openSession()`
- Allow sessions to open with any positive opening float (>0)
- Preserve `cash_threshold` in terminal policy for downstream monitoring and calculation services
- Ensure all existing uses of `cash_threshold` (summary, monitor, safe drop) remain functional

**Non-Goals:**
- Change terminal policy schema or migration
- Modify the finalize flow or variance approval logic
- Alter request validation (request layer already validates opening_float_total > 0)
- Change `PosSessionSummaryService`, `PosSessionMonitorService`, or `PosSafeDropService` implementations

## Decisions

### 1. Remove Lines 114-120 from PosSessionLifecycleService.openSession()

**Decision**: Delete the entire threshold validation block:
```php
if ($terminal->policy->cash_threshold === null) {
    throw new DomainException('Terminal policy not configured: cash_threshold is missing.');
}

if ($openingTotal <= (float) $terminal->policy->cash_threshold) {
    throw new DomainException('Opening float total must be greater than terminal cash threshold.');
}
```

**Rationale:**
- This is the only place `cash_threshold` is used as a validation gate
- The field is still loaded (resolver still fetches policy) and available if needed
- No downstream code depends on this validation happening at open time
- Removes the only blocker preventing valid session opens

**Alternatives Considered:**
- Keep validation but make it optional/configurable: Adds complexity without solving the semantic issue
- Move validation to request layer: Doesn't fix the problem—same gating logic, just different location
- Deprecate the field: Unnecessary—it's still useful for monitoring; we just stop using it for gating

### 2. Keep Policy Loading in Terminal Resolution

**Decision**: The terminal resolver call at line 112 continues to load the full policy object:
```php
$terminal = $this->terminalResolver->resolveForSessionOpen($settingId, $normalizedTerminalId);
```

**Rationale:**
- Policy is still needed by session creation for other fields (if any use in future, or for consistency)
- Summary, monitor, and safe drop services later load the policy again—no change to their load patterns
- Minimal cost to keep it loaded; doesn't affect performance

**Alternatives Considered:**
- Stop loading policy entirely: Would break if other code expects it; risky without broader audit
- Load policy only on demand: Adds complexity; not needed for this change

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **User confusion**: Cashiers may open sessions with very small floats, unaware of policy limits | Stakeholder visibility via monitoring dashboard (future feature). Help text during session open can clarify policy expectations. |
| **Undetected policy violations**: Sessions that should be flagged (below policy) are not flagged at open time | Acceptable—monitoring/summary services still flag them. Open-time validation was too strict. |

## Migration Plan

1. **Code change** (low risk, no data migration):
   - Edit `PosSessionLifecycleService.openSession()`, remove lines 114-120

2. **Testing**:
   - Verify existing session open tests still pass
   - Add test for opening with float < previous threshold (should succeed now)
   - Run summary/monitor/safe drop tests to ensure threshold calculations still work

3. **Deployment**:
   - Code-only change; no database migrations or config changes
   - Can be deployed independently

4. **Rollback**:
   - Revert the service file
   - No data cleanup needed

## Open Questions

None. Implementation is straightforward.
