## 1. Routes & Controllers

- [x] 1.1 Remove `pos.sessions.close-admin` route from `Modules/Pos/Routes/web.php` (line 150)
- [x] 1.2 Update route middleware/naming if needed for unified `/pos/sessions/{session}/close` endpoint
- [x] 1.3 Create new `close()` method in `PosSessionController` that handles both authorization cases
- [x] 1.4 Move authorization logic from both `closeFinalize()` and `closeAdmin()` into new `close()` method
- [x] 1.5 Remove `closeFinalize()` method from controller (no longer needed)
- [x] 1.6 Remove `closeAdmin()` method from controller (no longer needed)

## 2. View Templates

- [x] 2.1 Delete `Modules/Pos/Resources/views/session/_close-admin-modal.blade.php`
- [x] 2.2 Update `Modules/Pos/Resources/views/session/summary.blade.php` to remove "Tutup Sesi (Admin)" button (lines 85-89)
- [x] 2.3 Update "Tutup Sesi" button condition to show if user has `pos.sessions.close` OR `pos.sessions.close-admin` permission
- [x] 2.4 Verify `_close-modal.blade.php` remains as the single modal for both cases
- [x] 2.5 Remove `@include('pos::session._close-admin-modal')` from summary.blade.php line 218

## 3. JavaScript Handlers

- [x] 3.1 Update `public/js/pos-session-handlers.js` to remove closeAdminModal event listener initialization (lines 10-33)
- [x] 3.2 Remove `closeAdminModal.addEventListener('show.bs.modal', ...)` block
- [x] 3.3 Remove `populateCloseAdminModal()` function (lines 118-134)
- [x] 3.4 Remove `submitCloseAdmin()` function (lines 252-307)
- [x] 3.5 Update remaining `submitClose()` function to POST to `/pos/sessions/{id}/close` (no change needed, already correct)
- [x] 3.6 Test that single closeModal works for both standard and admin closes

## 4. Controller Authorization Logic

- [x] 4.1 In new `close()` method, check if user has `pos.sessions.close-admin` permission
- [x] 4.2 If admin permission granted, call `PosSessionAdminCloseService::closeSessionAsAdmin()`
- [x] 4.3 If only `pos.sessions.close` permission, validate session ownership before calling `PosSessionCloseService::closeSession()`
- [x] 4.4 Return appropriate error (403) if user lacks both permissions or is not session owner
- [x] 4.5 Ensure error messages are clear and consistent

## 5. Testing

- [x] 5.1 Test standard close: non-admin user closes their own session
- [x] 5.2 Test standard close denial: non-admin user attempts to close other user's session
- [x] 5.3 Test admin close: admin with `pos.sessions.close-admin` closes any session
- [x] 5.4 Test Super Admin bypass: Super Admin closes session in non-assigned setting
- [x] 5.5 Test modal behavior: single modal shows for both close types
- [x] 5.6 Test error responses: verify 403 messages are helpful
- [x] 5.7 Verify no 404 errors on old `/pos/sessions/{id}/close-admin` endpoint (route removed)

## 6. Documentation & Cleanup

- [x] 6.1 Update any API documentation that references `/pos/sessions/{id}/close-admin`
- [x] 6.2 Remove comments or TODOs related to dual endpoints
- [x] 6.3 Verify no other views reference `_close-admin-modal.blade.php`
- [x] 6.4 Check git history to ensure old references are removed
