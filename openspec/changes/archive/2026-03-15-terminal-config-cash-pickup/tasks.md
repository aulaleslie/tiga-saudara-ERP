## 1. Terminal Configuration Form Updates

- [x] 1.1 Remove `close_variance_approval_threshold` field from `Modules/Pos/Resources/views/terminals/_form.blade.php` (lines 84-89)
- [x] 1.2 Add help text and description to `cash_threshold` field explaining its purpose in monitor dashboard
- [x] 1.3 Add default value of 5,000,000 to `cash_threshold` in form display and form request validation
- [x] 1.4 Update form field to show currency formatting hint (e.g., "Rp 1.000.000,00") via Bootstrap helper or custom formatting
- [x] 1.5 Create database migration to drop `close_variance_approval_threshold` column from `pos_terminal_policies` table

## 2. POS Sell View - Dropdown and Modal UI

- [x] 2.1 Add "Pengambilan Kas" button to dropdown menu in `Modules/Pos/Resources/views/sell.blade.php` (around line 738, before closing dropdown-menu)
- [x] 2.2 Create new modal `pos-cash-pickup-modal` with two-step layout:
  - Step 1: Amount input (terminal/cashier info display, amount field, validation messages, "Lanjut" button)
  - Step 2: Supervisor credentials (confirmation text, email input, password input, "Konfirmasi Pengambilan" button, loading state)
- [x] 2.3 Embed session data (terminal code/name, cashier name, expected cash total, session ID) in page as hidden data attributes or JavaScript variables for modal population
- [x] 2.4 Add CSS styling for modal to match POS design (responsive, readable form labels, proper spacing)

## 3. POS Sell View - JavaScript Logic

- [x] 3.1 Add JavaScript listener for `#pos-cash-pickup-btn` click that opens modal and populates session info from page data
- [x] 3.2 Implement Step 1 validation:
  - Amount must be > 0
  - Amount must be ≤ expected_cash_total
  - Show/hide error messages based on validation state
  - "Lanjut" button only enabled when amount is valid
- [x] 3.3 Implement Step 1 → Step 2 transition: hide step 1, show step 2, display confirmation amount in currency format
- [x] 3.4 Implement Step 2 back button: show step 1, hide step 2 (preserve entered amount)
- [x] 3.5 Implement Step 2 submit handler:
  - Client-side validation: email not empty, password not empty
  - Show loading spinner during submission
  - POST to `/pos/sessions/{sessionId}/pickup` with amount, supervisor_email, supervisor_password
  - Handle success: close modal, show toast with updated cash total
  - Handle error: display error message in Step 2, stop loading spinner

## 4. Backend API Endpoint

- [x] 4.1 Create new controller method `PosSessionController::pickup()` that:
  - Validates request: amount (numeric, > 0, ≤ expected_cash), supervisor_email (required), supervisor_password (required)
  - Retrieves active session by ID and locks for update
  - Calls `PosSupervisorApprovalService::approveSafeDrop()` with supervisor email and password
  - Calls `PosSafeDropService::createSafeDrop()` with validated amount and supervisor info
  - Returns JSON response with success message and expected_cash_after
  - Handles errors: return appropriate HTTP status (403 for invalid credentials, 422 for validation errors)
- [x] 4.2 Register route in `Modules/Pos/Routes/web.php`: `POST /pos/sessions/{id}/pickup` → middleware: auth, name: `pos.sessions.pickup`
- [x] 4.3 Add permission check in controller to ensure request context is valid (user belongs to setting)

## 5. Testing

- [x] 5.1 Manual test: Form fields display correctly (description, default value, formatting) on terminal create/edit
- [x] 5.2 Manual test: "Pengambilan Kas" button visible in POS dropdown menu
- [x] 5.3 Manual test: Modal opens and displays correct session info when button clicked
- [x] 5.4 Manual test: Step 1 validation works (rejects 0/negative/excess amounts, shows errors)
- [x] 5.5 Manual test: Step 1 → Step 2 transition works correctly
- [x] 5.6 Manual test: Step 2 back button returns to Step 1 with amount preserved
- [x] 5.7 Manual test: Successful pickup with valid supervisor credentials shows success toast
- [x] 5.8 Manual test: Invalid supervisor email shows error
- [x] 5.9 Manual test: Invalid supervisor password shows error
- [x] 5.10 Manual test: Supervisor without `pos.safeDrops.approve` permission is rejected
- [x] 5.11 Manual test: Expected cash is correctly reduced after pickup
- [x] 5.12 Manual test: Drawer opens if `auto_open_drawer_on_pickup` policy is enabled
- [x] 5.13 Verify database migration: `close_variance_approval_threshold` column successfully dropped

## 6. Documentation & Cleanup

- [x] 6.1 Update code comments in new endpoint to explain supervisor authentication flow
- [x] 6.2 Verify no leftover console.logs or debugging code in JavaScript
- [x] 6.3 Confirm migration includes rollback procedure in comments
