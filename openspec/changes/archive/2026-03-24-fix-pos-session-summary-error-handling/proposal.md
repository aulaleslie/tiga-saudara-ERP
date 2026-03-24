## Why

The POS session summary endpoint (`GET /pos/sessions/{id}/summary`) currently lacks error handling for exceptions thrown by business logic. When the `PosSessionSummaryService` or `PosSessionExpectedCashCalculator` encounter errors, unhandled exceptions bubble up and Laravel renders HTML error pages instead of returning proper JSON error responses. This causes frontend JavaScript to fail with "Unexpected token '<'" when it expects JSON, preventing the finalization modal from loading session data.

This pattern is inconsistent with other POS session endpoints (`close` and `finalize`) which properly catch and return JSON errors.

## What Changes

- Add try-catch error handling around `getSummary()` call in `PosSessionController::summary()`
- Catch `DomainException` and return JSON 422 response
- Catch generic exceptions and return JSON 500 response
- Ensure all error responses are JSON, regardless of whether the request originates from modal or view context
- Maintain existing authorization and view logic

## Capabilities

### New Capabilities

(none)

### Modified Capabilities

- `pos-session-summary-endpoint`: Add error handling for exceptions to ensure JSON responses on failures

## Impact

- **Code**: `Modules/Pos/Http/Controllers/PosSessionController::summary()`
- **Affected**: Frontend modal finalization flow that depends on `/pos/sessions/{id}/summary` API
- **API Contract**: Endpoint will now return JSON error responses (422/500) instead of HTML error pages when exceptions occur
- **Breaking**: No - this only adds error cases that previously failed; success cases unchanged
