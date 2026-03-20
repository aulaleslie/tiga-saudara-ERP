## 0. Backend Terminal Requirement Logic & Permission Fixes

- [x] 0.1 Update `PosRolePolicyService::requiresTerminalSelection()` to check `pos.sell` permission instead of role type
- [x] 0.2 Modify `PosSessionLifecycleService` to allow opening without terminal when not required by role
- [x] 0.3 Make opening float validation conditional on terminal selection (required only if terminal selected)
- [x] 0.4 Update `PosSessionController::store()` to redirect based on user permissions after session opens
- [x] 0.5 Grant `pos.sessions.view` permission to "Pembantu Kasir" role for session access

## 1. Backend Validation Rules

- [x] 1.1 Update `StorePosSessionOpenRequest::rules()` to make `opening_float_total` required only when terminal_id is provided
- [x] 1.2 Verify validation logic: `$hasTerminal ? 'required' : 'nullable'`
- [x] 1.3 Test validation with various input combinations (terminal + saldo, terminal only, saldo only, neither)

## 2. Frontend Field Visibility Control

- [x] 2.1 Update `Modules/Pos/Resources/views/session/open.blade.php` to check user's `pos.sell` permission
- [x] 2.2 Wrap Terminal selection and Total Saldo Awal fields in `@if($canSell)` conditional block
- [x] 2.3 Keep Notes field visible to all users with `pos.sessions.open` permission
- [x] 2.4 Verify form renders correctly for users with and without `pos.sell`

## 3. Dynamic Field Requirement Indicators

- [x] 3.1 Add JavaScript event listener to Terminal dropdown for `change` events
- [x] 3.2 Implement `updateSaldoRequirement()` function to toggle required attribute on Saldo field
- [x] 3.3 Update label indicators: show "*" when required, "(Opsional)" when optional
- [x] 3.4 Add null-safety checks for terminal dropdown element
- [x] 3.5 Test dynamic updates: select terminal → Saldo becomes required, clear terminal → Saldo becomes optional
- [x] 3.6 Verify form still loads correctly when page refreshes with pre-selected values

## 4. Number Formatting for Total Saldo Awal

- [x] 4.1 Maintain existing number formatting logic (already in place, no changes needed)
- [x] 4.2 Verify formatted input still works with new required/optional logic
- [x] 4.3 Test that cursor position preservation works correctly

## 5. Testing & Verification

- [x] 5.1 Test user WITH `pos.sessions.open` + `pos.sell`: Fields visible, form works as before
- [x] 5.2 Test user WITH `pos.sessions.open` only: Terminal/Saldo fields hidden, Notes visible
- [x] 5.3 Test user WITHOUT `pos.sessions.open`: 403 error (unchanged behavior)
- [x] 5.4 Test form submission with terminal selected but no saldo: Validation error expected
- [x] 5.5 Test form submission without terminal and without saldo: Form accepted
- [x] 5.6 Test dynamic label/requirement updates work in real browser
- [x] 5.7 Test that old browser graceful degradation works (HTML5 validation as fallback)

## 6. Documentation & Cleanup

- [x] 6.1 Verify no console errors in browser dev tools
- [x] 6.2 Check that all field help text is clear and correct
- [x] 6.3 Confirm no breaking changes for existing users
- [x] 6.4 Update MEMORY.md if this changes any documented patterns
- [x] 6.5 Fix session index view to handle null terminal in sessions list
