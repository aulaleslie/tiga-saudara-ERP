## 1. Frontend: UI Visibility & Guidance

- [ ] 1.1 Update `Modules/Pos/Resources/views/session/index.blade.php` to show "Finalize" button in disabled state for `OPEN` sessions.
- [ ] 1.2 Add Bootstrap tooltip to the disabled "Finalize" button explaining the requirement to close the terminal first.
- [ ] 1.3 Update `Modules/Pos/Resources/views/session/_finalize-modal.blade.php` to include a hidden supervisor authorization section (Email & Password).

## 2. Frontend: Interactive Override Logic

- [ ] 2.1 Update `public/js/pos-session-handlers.js` to catch 422 `requires_variance_approval` errors.
- [ ] 2.2 Implement logic to unhide the supervisor authorization section in the modal on 422.
- [ ] 2.3 Modify `submitFinalize` to optionally send `supervisor_identifier` and `supervisor_password` if provided.
- [ ] 2.4 Add dynamic feedback for successful/failed override attempts within the modal.

## 3. Backend: Service & Controller Updates

- [ ] 3.1 Update `PosSessionController::finalize` to accept optional supervisor credentials from the request.
- [ ] 3.2 Update `PosSessionFinalizeService::finalizeSession` to support an optional supervisor override check if the primary user lacks permission.
- [ ] 3.3 Ensure the transition to `FINALIZED` records the correct approver metadata when an override is used.

## 4. Verification & Testing

- [ ] 4.1 Create/Update feature test `Modules/Pos/Tests/Feature/PosSessionFinalizeTest.php` to cover the override scenario.
- [ ] 4.2 Manually verify the full "blocked -> override -> success" flow in the browser.
