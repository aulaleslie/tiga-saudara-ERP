## Why

Sensitive POS operations (cash pickup, session finalization) currently rely on supervisor email + password entry at the terminal. This is slow, exposes passwords on shared devices, and provides no cryptographic proof of who authorized what. TOTP (Time-based One-Time Password) via authenticator apps like Google Authenticator provides faster, more secure action-level verification — 6 digits instead of full credentials, with 30-second rotation.

## What Changes

- Users can register a TOTP authenticator app from their profile page (self-service)
- Profile page shows QR code for scanning, manual entry key, and 6-digit confirmation flow
- Recovery codes generated at setup for account recovery if device is lost
- Profile page includes a "test code" feature so users can verify their authenticator works
- Users can disable their own 2FA from profile
- Admins can reset a user's 2FA from the user edit page
- New `TwoFactorService` encapsulates all TOTP logic (generate, verify, enable, disable, recovery)
- Three new encrypted columns on `users` table: `two_factor_secret`, `two_factor_confirmed_at`, `two_factor_recovery_codes`
- Two new composer dependencies: `pragmarx/google2fa-laravel` and `bacon/bacon-qr-code`

## Capabilities

### New Capabilities
- `totp-registration`: TOTP secret generation, QR code rendering, confirmation flow, and recovery code generation. Covers the full lifecycle of registering, enabling, testing, disabling, and admin-resetting a user's authenticator.
- `totp-profile-ui`: Profile page 2FA section with three states (not set up, setup in progress, active) plus inline test verification. Admin user edit page section for viewing status and resetting 2FA.

### Modified Capabilities

## Impact

- **Database**: New migration adding 3 nullable columns to `users` table
- **Model**: `User` model gains encrypted casts and `hasTwoFactorEnabled()` helper
- **Dependencies**: Two new composer packages (`pragmarx/google2fa-laravel`, `bacon/bacon-qr-code`)
- **Routes**: New routes under `/user/profile/2fa/*` in User module
- **Controllers**: New `TwoFactorController` in User module
- **Views**: New `_two-factor.blade.php` partial included in profile page; new section in user edit page
- **Services**: New `TwoFactorService` in `app/Services/`
- **No breaking changes**: Existing auth flows, profile update, and password change are untouched
