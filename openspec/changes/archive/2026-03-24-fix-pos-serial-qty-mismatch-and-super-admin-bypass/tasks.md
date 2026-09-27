## 1. Backend - Serial Preservation

- [x] 1.1 Remove serial clearing logic from PosCartService::updateLine() (lines 262-266)
- [x] 1.2 Test that assigned_serials are preserved when qty increases
- [x] 1.3 Test that assigned_serials are preserved when qty decreases
- [x] 1.4 Verify existing guard (qty < serialCount) still prevents invalid state at API level
- [x] 1.5 Ensure serial count validation tests pass

## 2. Backend - Super Admin Bypass

- [x] 2.1 Add Super Admin role detection in PosCartActionAuthorizationService::authorize()
- [x] 2.2 Check user.hasRole('Super Admin') before checking permissions
- [x] 2.3 Return authorized=true if Super Admin, bypassing token validation
- [x] 2.4 Test Super Admin can reduce qty without approval token
- [x] 2.5 Test Super Admin without pos.cart.line.reduce permission can still reduce qty
- [x] 2.6 Test non-Super-Admin still requires approval when lacking permission
- [x] 2.7 Ensure existing role-based authorization tests continue to pass

## 3. Frontend - Mismatch Validation and UI

- [x] 3.1 Verify allSerialsValid logic in sell.blade.php (lines 2423-2430) is correct
- [x] 3.2 Add clear error message when serial-qty mismatch exists
- [x] 3.3 Display mismatch warning in cart status area (e.g., "Line 1: 2 serials assigned but qty is 1")
- [x] 3.4 Test that Save Draft button is disabled on mismatch
- [x] 3.5 Test that Checkout button is disabled on mismatch
- [x] 3.6 Test that buttons are re-enabled when user manually resolves mismatch
- [x] 3.7 Test that qty reduction preserves serials and shows mismatch warning

## 4. Integration Testing

- [x] 4.1 Create test: Super Admin reduces qty with serials assigned → no approval required
- [x] 4.2 Create test: Super Admin reduces qty → serials preserved → save blocked until resolved
- [x] 4.3 Create test: Non-Super-Admin reduces qty → approval required (existing behavior)
- [x] 4.4 Create test: User removes serial to match reduced qty → save becomes enabled
- [x] 4.5 Create test: User increases qty to match serial count → save becomes enabled
- [x] 4.6 Run full POS checkout test suite to ensure no regressions
- [x] 4.7 Verify POSSerialValidationCheckoutTest still passes
- [x] 4.8 Verify POSCartReduceQtyWithApprovalTest still passes (non-Super-Admin path)

## 5. Documentation and Cleanup

- [x] 5.1 Update code comments in PosCartService::updateLine() explaining serial preservation
- [x] 5.2 Update code comments in PosCartActionAuthorizationService::authorize() explaining Super Admin bypass
- [x] 5.3 Ensure no dead code or commented-out serial-clearing logic remains
- [x] 5.4 Run code quality checks (linting, static analysis)

