# Tasks: fix-pos-terminal-optional-session-open

## 1. Update Validation Rules

- [x] 1.1 Update `StorePosSessionOpenRequest.rules()` to always treat `terminal_id` as nullable
- [x] 1.2 Update `opening_float_total` to be required only when `terminal_id` is present
- [x] 1.3 Verify validation logic removes permission-based conditionals

## 2. Update Controller Logic

- [x] 2.1 Remove `$requiresTerminalSelection` variable assignment from `PosSessionController.create()`
- [x] 2.2 Remove `requiresTerminalSelection` from data passed to view
- [x] 2.3 Verify controller no longer calls `$rolePolicy->requiresTerminalSelection()`

## 3. Simplify View Template

- [x] 3.1 Remove `@if($requiresTerminalSelection)` conditional branch from `open.blade.php`
- [x] 3.2 Remove the info alert showing "no terminal required" message
- [x] 3.3 Create single, unified form where terminal is always shown as optional
- [x] 3.4 Remove red asterisk (*) from terminal field label
- [x] 3.5 Update JavaScript to only show opening float when terminal is selected
- [x] 3.6 Update helper text to clarify terminal is optional

## 4. Clean Up Service Class

- [x] 4.1 Remove `requiresTerminalSelection()` method from `PosRolePolicyService`
- [x] 4.2 Remove `requires_terminal_selection` from `capabilityFlags()` return array
- [x] 4.3 Verify no other code calls the removed method

## 5. Update Tests

- [x] 5.1 Update `POSSessionRoleTerminalAllocationTest.php` to test that all users can open sessions without terminal
- [x] 5.2 Update `test_user_with_terminal_required_permission_must_select_terminal()` or remove if no longer applicable
- [x] 5.3 Update `POSRoleMatrixEnforcementTest.php` tests to reflect optional terminal
- [x] 5.4 Update `POSOpeningFloatCaptureTest.php` to test float behavior with/without terminal
- [x] 5.5 Update `POSCheckoutFinalizeIdempotencyTest.php` if it assumes terminal selection logic
- [x] 5.6 Add new test: user can open session without terminal for all permission levels
- [x] 5.7 Add new test: opening float is optional when no terminal selected

## 6. Integration & Verification

- [x] 6.1 Run full test suite to ensure no regressions
- [x] 6.2 Manually test session opening as different user roles (Super Admin, Manager, Cashier, Helper)
- [x] 6.3 Verify form shows no required asterisk on terminal field
- [x] 6.4 Verify sessions can be created without terminal
- [x] 6.5 Verify sessions can still be created with terminal
- [x] 6.6 Verify opening float is optional when no terminal
- [x] 6.7 Verify opening float is required when terminal selected
