## Why

POS session opening currently validates opening float (Saldo Awal) against the terminal's `cash_threshold` policy setting, blocking session creation if the float doesn't exceed the threshold. This is semantically incorrect: `cash_threshold` is a monitoring/informational limit (indicating when a terminal holds too much cash for safety), not a minimum opening balance requirement. The validation was accidentally re-introduced in commit 9172c74 after being intentionally removed in 0f7c215. Removing it restores the correct behavior and allows operators to open sessions with valid opening floats below the policy threshold.

## What Changes

- **Remove threshold validation from PosSessionLifecycleService.openSession()**: Delete lines 117-131 that validate opening float against terminal policy's `cash_threshold`
- **Preserve > 0 validation**: Keep the existing check that opening float must be positive (lines 80-82) when a terminal is selected
- **Preserve cash_threshold for monitoring**: The field remains available in terminal policy for downstream monitoring services (PosSessionSummaryService, PosSessionMonitorService, PosSafeDropService)

## Capabilities

### New Capabilities

(None - this is a correction, not a new feature)

### Modified Capabilities

- `pos-session-lifecycle`: Session opening validation no longer gates on `cash_threshold`; sessions can open with any positive opening float

## Impact

- **PosSessionLifecycleService.php**: Remove threshold validation block (8 lines)
- **Tests**: Existing test `POSOpeningFloatCaptureTest::test_it_rejects_when_opening_float_total_is_less_than_or_equal_to_cash_threshold()` becomes outdated and will need updating/removal
- **User impact**: Operators can now open sessions with opening floats below terminal policy threshold; monitoring dashboards still flag these sessions via threshold tracking
