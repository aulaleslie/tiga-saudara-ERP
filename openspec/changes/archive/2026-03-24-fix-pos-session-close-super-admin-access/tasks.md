## 1. Fix Super Admin Permission Assignment

- [x] 1.1 Update `SuperUserSeeder` to fetch all permissions and sync them to the Super Admin role
- [x] 1.2 Verify the seeder correctly handles the case where the role already exists (idempotent)
- [x] 1.3 Test that `php artisan db:seed --class=SuperUserSeeder` assigns all permissions to Super Admin

## 2. Add Route Parameter Type Constraints

- [x] 2.1 Add `->whereNumber('session')` constraint to `/pos/sessions/{session}/summary` route (line 23)
- [x] 2.2 Add `->whereNumber('session')` constraint to `/pos/sessions/{session}/safe-drops` route (line 43)
- [x] 2.3 Add `->whereNumber('session')` constraint to `/pos/sessions/{session}/pickup` route (line 48)
- [x] 2.4 Add `->whereNumber('session')` constraint to `/pos/sessions/{session}/close` route (line 52)
- [x] 2.5 Add `->whereNumber('session')` constraint to `/pos/sessions/{session}/finalize` route (line 150)

## 3. Verify Gate Configuration

- [x] 3.1 Confirm `AuthServiceProvider` has the `Gate::before()` callback for Super Admin (should already exist at lines 28-32)
- [x] 3.2 Verify the gate logic returns `true` for Super Admin role before permission checks

## 4. Integration Testing

- [x] 4.1 Test Super Admin can close a POS session at `/pos/sessions/{id}/close` without permission errors
- [x] 4.2 Test Super Admin can view session summary at `/pos/sessions/{id}/summary` without errors
- [x] 4.3 Test Super Admin can finalize a session at `/pos/sessions/{id}/finalize` without errors
- [x] 4.4 Test Super Admin can create safe drops at `/pos/sessions/{id}/safe-drops` without errors
- [x] 4.5 Test Super Admin can perform cash pickup at `/pos/sessions/{id}/pickup` without errors
- [x] 4.6 Test invalid session ID (e.g., `/pos/sessions/abc/close`) returns 404, not type error
- [x] 4.7 Test non-Super Admin users still require appropriate permissions (no regression)
- [x] 4.8 Test non-Super Admin users cannot close other cashiers' sessions (no regression)

## 5. Deployment & Verification

- [x] 5.1 Run seeder on staging environment and verify permissions are synced
- [x] 5.2 Clear permission caches if applicable: `php artisan cache:clear`
- [x] 5.3 Verify Super Admin user has all permissions in the role_has_permissions table
- [x] 5.4 Perform manual testing of session close workflow as Super Admin on staging
- [x] 5.5 Verify error logs show no type errors for session endpoints
