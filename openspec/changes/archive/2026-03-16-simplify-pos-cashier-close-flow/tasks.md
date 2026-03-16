## 1. Service Refactoring

- [x] 1.1 Update `PosSessionCloseService::closeSession()` signature: remove `$countedCashTotal`, `$countedDenominations`, `$supervisorIdentifier`, `$supervisorPin` parameters; keep only `$settingId`, `$sessionId`, `$actorUserId`, `?string $reason = null`
- [x] 1.2 Remove all variance calculation logic from `closeSession()` (lines 106-152 calculating expected cash, variance, and approval)
- [x] 1.3 Simplify the transaction to: validate session exists, validate actor is cashier, transition status to CLOSED (via `sessionLifecycleService`), optionally record reason in metadata, return success response
- [x] 1.4 Remove calls to `supervisorApprovalService` from `closeSession()`
- [x] 1.5 Ensure the service no longer creates `PosSessionCashEvent` with variance metadata; if creating a close event, make it minimal (no variance details)
- [x] 1.6 Update return response to include only: `session_id`, `status` (CLOSED), `closed_at`; no approval_result, no variance fields

## 2. Request Validation

- [x] 2.1 Update `StorePosSessionCloseRequest::rules()` to accept only `reason` field
- [x] 2.2 Remove validation rules for: `counted_cash_total`, `counted_denominations`, `supervisor_identifier`, `supervisor_pin`
- [x] 2.3 Verify request rules now look like: `['reason' => ['nullable', 'string', 'max:500']]`

## 3. Controller Updates

- [x] 3.1 Update `PosSessionController::closeFinalize()` method signature and implementation
- [x] 3.2 Remove all variance-related error handling and response branches (the blocking response logic that returns 422 with variance approval message)
- [x] 3.3 Simplify to: auth check, setting validation, session lookup, call service with only `$reason`, handle DomainException and AuthorizationException
- [x] 3.4 Remove the "blocked" response path entirely (service will no longer return it)
- [x] 3.5 Verify method returns simple success JSON with session details

## 4. Frontend - Sell Page

- [x] 4.1 Locate the close session modal in sell.blade.php (around line 3342-3462)
- [x] 4.2 Remove or disable the close session modal HTML entirely (including `pos-close-session-modal` div and related elements)
- [x] 4.3 Remove or simplify the close session event listener JavaScript (the code that shows the modal and handles approval logic)
- [x] 4.4 Replace with simple confirmation: either add a confirm() dialog before fetch, or show a toast on close without modal
- [x] 4.5 Update the close button fetch call to send only `reason` (or empty body) instead of cash/denominations/supervisor data
- [x] 4.6 Update success callback: redirect to home or show simple toast "Terminal released"

## 5. Testing

- [x] 5.1 Review existing `StorePosSessionCloseRequest` tests; update or remove tests for removed fields
- [x] 5.2 Review existing `PosSessionCloseService` tests; remove tests for variance calculation and supervisor approval logic
- [x] 5.3 Add test: cashier can close session successfully → status becomes CLOSED, closed_at is set
- [x] 5.4 Add test: cashier can close session with optional reason → reason is stored in metadata
- [x] 5.5 Add test: non-cashier cannot close session → returns 403 AuthorizationException
- [x] 5.6 Add test: close response includes only session_id, status, closed_at (no approval fields)
- [x] 5.7 Run existing finalize tests to ensure they still pass (finalize should be unaffected)

## 6. Integration & Verification

- [x] 6.1 Manually test: cashier opens session, adds items to cart, closes session via sell page
- [x] 6.2 Verify: terminal releases immediately, no modal, no error, redirects to home
- [x] 6.3 Manually test: supervisor navigates to sessions index, finds CLOSED session, clicks Finalize button
- [x] 6.4 Verify: finalize flow works (shows reconciliation modal, allows variance approval)
- [x] 6.5 Verify: no broken links or console errors from removed modal code
- [x] 6.6 Run full test suite: all POS tests pass

## 7. Documentation & Cleanup

- [x] 7.1 Remove any obsolete comments or references to variance approval during close
- [x] 7.2 Update code comments in `PosSessionCloseService` to clarify: "Closes terminal and releases for reuse. Variance reconciliation happens in finalize stage."
- [x] 7.3 Check git diff for any accidentally-left debugging code or print statements
- [x] 7.4 Commit with clear message: "refactor(pos): simplify cashier close to pure terminal release"
