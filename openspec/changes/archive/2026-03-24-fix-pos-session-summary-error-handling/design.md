## Context

The POS session summary endpoint is called via AJAX from the finalization modal (`pos-session-handlers.js` line 126) which expects a JSON response. The controller currently lacks error handling, so any exception thrown by `PosSessionSummaryService::getSummary()` (line 209) bubbles up uncaught and is rendered as an HTML error page by Laravel's default exception handler.

The pattern to follow already exists in the same controller:
- `close()` method (line 299): Catches `DomainException` and `AuthorizationException`, returns JSON 422/403
- `finalize()` method (line 568): Same pattern with comprehensive error handling

## Goals / Non-Goals

**Goals:**
- Add proper exception handling to `summary()` method
- Return JSON error responses instead of HTML error pages
- Align with error handling patterns in `close()` and `finalize()` endpoints
- Maintain existing authorization and data loading logic
- Ensure frontend modal receives parseable JSON on all error conditions

**Non-Goals:**
- Change the summary data structure or calculations
- Modify authorization requirements
- Create new services or utilities (this is a straightforward try-catch addition)
- Change how the view is rendered for successful HTML responses

## Decisions

### Decision 1: Exception Types to Catch

**Choice**: Catch `DomainException` (business logic errors) and generic `Exception` separately

**Rationale**:
- `DomainException` indicates validation/business logic failures (e.g., session not found, invalid data)
  - Should return 422 with specific error message
  - Already used by `PosSessionExpectedCashCalculator::calculate()` (line 32, 66)
- Generic `Exception` as a catch-all for unexpected errors (database issues, timeouts, etc.)
  - Should return 500 with safe generic message
  - Prevents HTML error page rendering

**Alternative considered**: Just catch all exceptions as one type
- Would work but loses ability to distinguish business errors from infrastructure errors
- Rejected: Less helpful for debugging and API consistency

### Decision 2: Request Context (JSON vs View)

**Choice**: Always return JSON when exceptions occur, regardless of Accept header

**Rationale**:
- The endpoint already checks Accept header for success path (line 211)
- On error, frontend JavaScript needs parseable JSON to handle the error
- HTML error page never useful in this context (AJAX modal, not a navigation)
- Simpler than checking Accept header in exception handler

### Decision 3: Error Message Exposure

**Choice**: Return exception message for `DomainException`, generic message for other exceptions

**Rationale**:
- `DomainException` messages are application logic, safe to expose
- Generic exceptions may contain sensitive info, return safe message
- Follows principle of least privilege for error information

## Risks / Trade-offs

**Risk**: Database lock timeout in `expectedCashCalculator->calculate()` (line 53)
- Line 28-29 uses `lockForUpdate()` in a transaction
- Potential for timeouts if session is locked by another process
- **Mitigation**: The try-catch will catch this and return 422; timeout is already handled by Laravel's database layer

**Risk**: N+1 query issues if relations aren't eager-loaded properly
- Line 44 loads `cashEvents.performer` and `cashEvents.approver`
- **Mitigation**: Relations are properly defined in `PosSessionCashEvent` entity; this is existing code that works

**Trade-off**: Generic "Internal server error" message vs specific infrastructure errors
- Users won't know if it's database, cache, or service issue
- **Rationale**: Security best practice; infrastructure details don't help end users
