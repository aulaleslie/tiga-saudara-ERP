## 1. Add Error Handling to Summary Endpoint

- [x] 1.1 Wrap getSummary() call in try-catch block in PosSessionController::summary()
- [x] 1.2 Catch DomainException and return JSON 422 with exception message
- [x] 1.3 Catch generic Exception and return JSON 500 with safe error message
- [x] 1.4 Ensure error responses include proper logging for debugging

## 2. Testing & Verification

- [x] 2.1 Test endpoint returns JSON on DomainException (e.g., session not found in summary service)
- [x] 2.2 Test endpoint returns JSON on unexpected exception
- [x] 2.3 Verify successful JSON response still works for AJAX requests
- [x] 2.4 Verify HTML view response still works for non-AJAX requests
- [x] 2.5 Test modal finalization flow shows proper error toast notification
- [x] 2.6 Verify logs contain appropriate debug/error information

## 3. Documentation & Cleanup

- [x] 3.1 Add changelog entry for the error handling fix
- [x] 3.2 Archive the change when complete
