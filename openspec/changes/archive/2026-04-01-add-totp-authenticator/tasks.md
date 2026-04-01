## 1. Dependencies & Database

- [x] 1.1 Install `pragmarx/google2fa-laravel` and `bacon/bacon-qr-code` via composer
- [x] 1.2 Create migration to add `two_factor_secret`, `two_factor_confirmed_at`, and `two_factor_recovery_codes` columns to `users` table

## 2. User Model

- [x] 2.1 Add encrypted casts for `two_factor_secret` and `two_factor_recovery_codes`, datetime cast for `two_factor_confirmed_at`
- [x] 2.2 Add `hasTwoFactorEnabled()` method returning boolean based on `two_factor_confirmed_at`

## 3. TwoFactorService

- [x] 3.1 Create `app/Services/TwoFactorService.php` with `generateSecretKey()` and `getQrCodeUri(User, secret)` methods
- [x] 3.2 Add `renderQrCodeSvg(uri)` method using bacon/bacon-qr-code
- [x] 3.3 Add `verifyCode(User, code)` method with ±1 period window
- [x] 3.4 Add `enableTwoFactor(User, secret)` method that encrypts secret, sets confirmed_at, generates and stores hashed recovery codes, returns plaintext codes
- [x] 3.5 Add `generateRecoveryCodes()` method returning 8 codes in `XXXXX-XXXXX` format
- [x] 3.6 Add `useRecoveryCode(User, code)` method that verifies against hashed codes and removes used code
- [x] 3.7 Add `disableTwoFactor(User)` method that nulls all three columns
- [x] 3.8 Add `resetTwoFactor(User)` method (alias for disable, used by admin context)

## 4. Routes & Controller

- [x] 4.1 Create `Modules/User/Http/Controllers/TwoFactorController.php` with `setup()`, `confirm()`, `test()`, `disable()`, and `adminReset()` actions
- [x] 4.2 Add 2FA routes to `Modules/User/Routes/web.php`: POST setup, POST confirm, POST test, DELETE disable, POST admin-reset

## 5. Profile UI

- [x] 5.1 Create `Modules/User/Resources/views/profile/_two-factor.blade.php` partial with three states (not set up, setup in progress via JS, active)
- [x] 5.2 Include the partial in `profile.blade.php` as a full-width row below existing cards
- [x] 5.3 Add JavaScript in the partial for AJAX setup flow (call setup endpoint, render QR code and manual key inline)
- [x] 5.4 Add JavaScript for AJAX confirm flow (submit 6-digit code, show recovery codes on success)
- [x] 5.5 Add JavaScript for AJAX test flow (submit code, show valid/invalid feedback inline)
- [x] 5.6 Add JavaScript for AJAX disable flow (DELETE request, transition card to not-set-up state)

## 6. Admin UI

- [x] 6.1 Add 2FA status section to `Modules/User/Resources/views/users/edit.blade.php` showing status and "Reset 2FA" button
- [x] 6.2 Wire "Reset 2FA" button to admin-reset endpoint

## 7. Testing

- [x] 7.1 Write feature tests for TwoFactorController (setup, confirm, test, disable, admin-reset)
- [x] 7.2 Write unit tests for TwoFactorService (generate, verify, enable, disable, recovery codes)
