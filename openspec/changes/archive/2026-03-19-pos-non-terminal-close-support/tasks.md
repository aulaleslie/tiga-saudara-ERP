## 1. Sessions Index Null-Safe Rendering

- [x] 1.1 Update session action button data attributes in `Modules/Pos/Resources/views/session/index.blade.php` to avoid direct `terminal->...` dereference when `terminal_id` is null
- [x] 1.2 Reuse the existing non-terminal fallback label in action metadata so OPEN non-terminal rows render without Blade exceptions
- [x] 1.3 Verify session rows with and without terminal keep identical table structure and action column layout

## 2. Admin-Close and Finalize Action Gating

- [x] 2.1 Keep admin force-close action available for OPEN non-terminal sessions under `pos.sessions.close-admin`
- [x] 2.2 Restrict finalize action visibility to CLOSED sessions with terminal context in `Modules/Pos/Resources/views/session/index.blade.php`
- [x] 2.3 Confirm non-terminal CLOSED sessions present no finalize action while terminal-backed CLOSED sessions remain unchanged

## 3. Frontend Modal/Data Handling Consistency

- [x] 3.1 Align `public/js/pos-session-handlers.js` session-code fallback labels with index-view non-terminal labeling
- [x] 3.2 Ensure modal initialization and submission flows remain stable when terminal metadata is absent
- [x] 3.3 Validate no new console/runtime errors during close-admin and finalize modal open events

## 4. Finalization Guardrail Validation

- [x] 4.1 Confirm POST `/pos/sessions/{session}/finalize` for terminal-less CLOSED sessions returns HTTP 422 with terminal-policy-missing context
- [x] 4.2 Keep existing finalize flow and variance approval behavior unchanged for terminal-backed sessions

## 5. Regression Test Coverage

- [x] 5.1 Add feature coverage ensuring `/pos/sessions` renders for privileged users when an OPEN session has `terminal_id = null`
- [x] 5.2 Add feature coverage ensuring admin-close control appears for OPEN non-terminal session rows
- [x] 5.3 Add feature coverage ensuring finalize control is hidden for CLOSED non-terminal rows and still shown for eligible terminal-backed rows
- [x] 5.4 Add feature/API coverage ensuring finalize endpoint rejects CLOSED non-terminal sessions with HTTP 422

## 6. Verification and Rollout Readiness

- [x] 6.1 Run targeted POS feature tests for session index, admin close, and finalization paths
- [x] 6.2 Perform manual browser verification on `/pos/sessions` with super-admin permissions using mixed terminal/non-terminal data
- [x] 6.3 Confirm no schema migrations or permission backfills are required for deployment
