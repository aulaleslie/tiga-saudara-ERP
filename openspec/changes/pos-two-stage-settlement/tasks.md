## 1. Database & Schema

- [x] 1.1 Add `FINALIZED` status constant to `Modules/Pos/Entities/PosSession.php`
- [x] 1.2 Update `PosSession::activeMarkerForStatus()` to return active marker (1) only for OPEN and CLOSING states
- [x] 1.3 Verify PosSession model has `finalized_at` column or add migration if needed (using `closed_at` field for now, or new column)
- [x] 1.4 Update PosSession fillable array to include `finalized_at` if new column is added

## 2. Permissions

- [x] 2.1 Add `pos.sessions.close-admin` permission to `app/Config/Permissions.php` under POS group with label "Tutup Terminal (Admin)"
- [x] 2.2 Add `pos.sessions.approve-variance` permission to `app/Config/Permissions.php` under POS group with label "Setujui Varians Kas"
- [x] 2.3 Run `php artisan db:seed --class=PermissionsTableSeeder` to sync permissions to database
- [x] 2.4 Verify permissions appear in role management UI

## 3. Services - Admin Force Close

- [x] 3.1 Create `Modules/Pos/Services/PosSessionAdminCloseService.php`
- [x] 3.2 Implement `closeSessionAsAdmin(int $settingId, int $sessionId, int $adminUserId, ?string $reason = null): array` method
- [x] 3.3 Validate admin user is assigned to setting
- [x] 3.4 Use transaction and SELECT FOR UPDATE lock on session
- [x] 3.5 Validate session is in OPEN status
- [x] 3.6 Update session: `status = 'CLOSED'`, `closed_by = $adminUserId`, `closed_at = now()`
- [x] 3.7 Add to metadata: `closed_by_role: 'admin'` and optional `admin_close_reason: $reason`
- [x] 3.8 Create PosSessionCashEvent with EVENT_CLOSE_COUNT, DIRECTION_NEUTRAL, amount = null
- [x] 3.9 Return success response with session data including status and closed_at

## 4. Services - Supervisor Finalization

- [x] 4.1 Create `Modules/Pos/Services/PosSessionFinalizeService.php`
- [x] 4.2 Implement `finalizeSession(int $settingId, int $sessionId, int $supervisorUserId, float $actualCashReceived, ?string $notes = null): array` method
- [x] 4.3 Validate supervisor user is assigned to setting
- [x] 4.4 Use transaction and SELECT FOR UPDATE lock on session
- [x] 4.5 Validate session is in CLOSED status
- [x] 4.6 Inject and use `PosSessionExpectedCashCalculator` to get expected_cash_total
- [x] 4.7 Calculate variance = actualCashReceived - expected_cash_total
- [x] 4.8 Get terminal policy and variance threshold
- [x] 4.9 If |variance| > threshold: Check supervisor has `pos.sessions.approve-variance` permission
- [x] 4.10 If permission missing: Return blocking response indicating variance approval required
- [x] 4.11 If variance approved or within threshold: Update session status = 'FINALIZED', `finalized_at = now()`
- [x] 4.12 Create PosSessionCashEvent with EVENT_FINALIZE_COUNT, DIRECTION_NEUTRAL, amount = actualCashReceived
- [x] 4.13 Store in event metadata: expected_cash_total, variance_total, variance_threshold
- [x] 4.14 Return success response with status, finalized_at, variance_total, approval_result

## 5. Routes & Middleware

- [x] 5.1 Add new route group in `Modules/Pos/Routes/web.php` for admin force-close:
  - [x] `POST /pos/sessions/{session}/close-admin` → `PosSessionController::closeAdmin()` (middleware: auth, role.setting, pos.enabled, can:pos.access, can:pos.sessions.close-admin)
- [x] 5.2 Add new route group for supervisor finalization:
  - [x] `POST /pos/sessions/{session}/finalize` → `PosSessionController::finalize()` (middleware: auth, role.setting, pos.enabled, can:pos.access, can:pos.supervisor.approval)
- [x] 5.3 Create route binding for session parameter to include setting validation

## 6. Controller Methods

- [x] 6.1 Add `closeAdmin(int $session, PosSessionAdminCloseService $service): JsonResponse` method to `PosSessionController`
- [x] 6.2 Validate current setting context
- [x] 6.3 Get session and verify it exists for current setting
- [x] 6.4 Call service and handle exceptions (DomainException, AuthorizationException)
- [x] 6.5 Return JSON response with session status, closed_at, closed_by
- [x] 6.6 Add `finalize(int $session, PosSessionFinalizeService $service): JsonResponse` method to `PosSessionController`
- [x] 6.7 Validate current setting context
- [x] 6.8 Get session and verify it exists for current setting
- [x] 6.9 Validate request input: `actual_cash_received` (required, numeric, >= 0)
- [x] 6.10 Call service with input and handle exceptions
- [x] 6.11 If service returns blocking response (variance approval needed): Return 422 with variance details
- [x] 6.12 Return JSON response with status, finalized_at, variance_total, variance_threshold, approval_result

## 7. UI - Session Index View

- [x] 7.1 Update `Modules/Pos/Resources/views/session/index.blade.php`
- [x] 7.2 For OPEN sessions: Add "Close Terminal (Admin)" action button (hidden if user lacks pos.sessions.close-admin)
- [x] 7.3 For CLOSED sessions: Add "Finalize" action button (hidden if user lacks pos.supervisor.approval)
- [x] 7.4 Both buttons should trigger modal dialogs instead of inline forms

## 8. UI - Admin Force-Close Modal

- [x] 8.1 Create partial blade template `Modules/Pos/Resources/views/session/_close-admin-modal.blade.php`
- [x] 8.2 Modal displays: terminal code, terminal name, cashier name, opened_at, session duration, opening_float_total
- [x] 8.3 Optional reason/note text field for audit trail
- [x] 8.4 Confirm / Cancel buttons
- [x] 8.5 JavaScript handler posts to `/pos/sessions/{session}/close-admin`
- [x] 8.6 On success: Close modal, refresh session table or show success toast

## 9. UI - Finalize Modal

- [x] 9.1 Create partial blade template `Modules/Pos/Resources/views/session/_finalize-modal.blade.php`
- [x] 9.2 Modal displays full reconciliation:
  - [x] 9.2a Header: terminal code, terminal name, cashier name, opened_at, duration
  - [x] 9.2b Sales summary: total sales, cash sales, non-cash sales
  - [x] 9.2c Expected cash breakdown: opening_float + cash_sales - safe_drops
  - [x] 9.2d Safe drop summary (if applicable)
  - [x] 9.2e INPUT FIELD: "Actual Cash Received" (currency, required)
  - [x] 9.2f Variance display: real-time calculation and color (red if > threshold)
- [x] 9.3 Optional notes/memo field
- [x] 9.4 Finalize / Cancel buttons
- [x] 9.5 JavaScript handler calculates variance in real-time as user types
- [x] 9.6 JavaScript handler posts to `/pos/sessions/{session}/finalize`
- [x] 9.7 On 422 variance approval required: Show variance details and message directing to manager
- [x] 9.8 On success: Close modal, refresh table, show success toast

## 10. JavaScript & AJAX Handlers

- [x] 10.1 Create or update JavaScript file handling modal interactions
- [x] 10.2 Force-close modal: AJAX POST to `/pos/sessions/{session}/close-admin`
- [x] 10.3 Finalize modal: AJAX POST to `/pos/sessions/{session}/finalize` with form data
- [x] 10.4 Real-time variance calculation in finalize form
- [x] 10.5 Error handling for both modals (display user-friendly error messages)
- [x] 10.6 Success callbacks to refresh session list or navigate

## 11. Feature Tests

- [x] 11.1 Create `Modules/Pos/Tests/Feature/POSAdminForceCloseTest.php`
- [x] 11.2 Test admin can force-close OPEN session → status becomes CLOSED
- [x] 11.3 Test admin close creates PosSessionCashEvent with EVENT_CLOSE_COUNT
- [x] 11.4 Test metadata stores closed_by_role: 'admin'
- [x] 11.5 Test user without pos.sessions.close-admin permission gets 403
- [x] 11.6 Test force-close not available for non-OPEN sessions
- [x] 11.7 Create `Modules/Pos/Tests/Feature/POSSessionFinalizeTest.php`
- [x] 11.8 Test supervisor can finalize CLOSED session with variance within threshold
- [x] 11.9 Test expected_cash_total calculation (opening_float + cash_sales - safe_drops)
- [x] 11.10 Test variance calculation: actual - expected
- [x] 11.11 Test finalization creates PosSessionCashEvent with EVENT_FINALIZE_COUNT
- [x] 11.12 Test finalization blocked if variance > threshold and user lacks pos.sessions.approve-variance
- [x] 11.13 Test supervisor with pos.sessions.approve-variance can approve variance
- [x] 11.14 Test user without pos.supervisor.approval cannot finalize
- [x] 11.15 Test finalization not available for OPEN or FINALIZED sessions
- [x] 11.16 Test FINALIZED session cannot be modified further

## 12. Integration & Documentation

- [x] 12.1 Update Permissions.php to include both new permissions in POS group
- [x] 12.2 Document new permissions in role/permission management area
- [x] 12.3 Add comments to new services explaining force-close vs finalize distinction
- [x] 12.4 Update any API documentation if maintained separately
- [x] 12.5 Manual testing: Force-close scenario in staging environment
- [x] 12.6 Manual testing: Finalize with variance approval scenario
- [x] 12.7 Verify all new status transitions (OPEN→CLOSED→FINALIZED) work correctly

## 13. Bug Fixes & Edge Cases

- [x] 13.1 Ensure concurrent force-close attempts are serialized (SELECT FOR UPDATE)
- [x] 13.2 Ensure concurrent finalize attempts are serialized (SELECT FOR UPDATE)
- [x] 13.3 Validate all monetary inputs to 2 decimal places
- [x] 13.4 Handle edge case: finalize with actual_cash_received < 0 (should be rejected)
- [x] 13.5 Handle edge case: session already FINALIZED attempts to finalize again
- [x] 13.6 Test behavior when PosSessionExpectedCashCalculator has issues

## 14. Deployment & Rollout

- [x] 14.1 Deploy code changes
- [x] 14.2 Run artisan seeder to add permissions
- [x] 14.3 Grant pos.sessions.close-admin to appropriate roles (admin/manager)
- [x] 14.4 Grant pos.sessions.approve-variance to appropriate roles (manager/owner)
- [x] 14.5 Test in production environment
- [x] 14.6 Document feature in internal wikis or user guides
