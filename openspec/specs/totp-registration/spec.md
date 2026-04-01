## ADDED Requirements

### Requirement: Generate TOTP secret
The system SHALL generate a unique 160-bit base32-encoded TOTP secret per user when they initiate 2FA setup. The secret SHALL be stored in the HTTP session until confirmed, NOT in the database.

#### Scenario: User initiates 2FA setup
- **WHEN** an authenticated user requests 2FA setup
- **THEN** the system generates a new TOTP secret and stores it in the session
- **AND** returns the secret and a QR code URI for the authenticator app

#### Scenario: User already has 2FA enabled
- **WHEN** an authenticated user with active 2FA requests setup
- **THEN** the system rejects the request with an error indicating 2FA is already active

### Requirement: QR code generation
The system SHALL generate an `otpauth://totp/` URI containing the app name, user email, and secret. The system SHALL render this URI as an inline SVG QR code.

#### Scenario: QR code contains correct parameters
- **WHEN** a QR code is generated for a user
- **THEN** the URI follows the format `otpauth://totp/{issuer}:{email}?secret={secret}&issuer={issuer}`
- **AND** the issuer SHALL be the application name

#### Scenario: Manual entry key provided
- **WHEN** the QR code is displayed
- **THEN** the plain-text secret SHALL also be displayed for manual entry into authenticator apps that cannot scan QR codes

### Requirement: Confirm TOTP setup with verification code
The system SHALL require the user to enter a valid 6-digit TOTP code from their authenticator app before enabling 2FA. This proves the user successfully scanned or entered the secret.

#### Scenario: Valid confirmation code
- **WHEN** the user enters a valid 6-digit code matching the session-stored secret
- **THEN** the system encrypts and persists the secret to `two_factor_secret` on the user record
- **AND** sets `two_factor_confirmed_at` to the current timestamp
- **AND** generates recovery codes and returns them to the user
- **AND** removes the temporary secret from the session

#### Scenario: Invalid confirmation code
- **WHEN** the user enters an invalid 6-digit code
- **THEN** the system rejects the confirmation with an error message
- **AND** the session-stored secret remains for retry

#### Scenario: No pending setup in session
- **WHEN** a confirmation is submitted without a prior setup request (no secret in session)
- **THEN** the system rejects with an error indicating setup must be initiated first

### Requirement: TOTP code verification
The system SHALL verify 6-digit TOTP codes against a user's stored secret with a time window of ±1 period (30 seconds each side, 90 seconds total) to account for clock drift.

#### Scenario: Valid code within time window
- **WHEN** a valid 6-digit code is submitted for a user with active 2FA
- **THEN** the system confirms the code as valid

#### Scenario: Expired or invalid code
- **WHEN** an invalid or expired 6-digit code is submitted
- **THEN** the system rejects the code as invalid

#### Scenario: User has no 2FA enabled
- **WHEN** a code verification is attempted for a user without active 2FA
- **THEN** the system rejects with an error indicating 2FA is not enabled

### Requirement: Recovery code generation
The system SHALL generate 8 single-use recovery codes when 2FA is enabled. Each code SHALL be in the format `XXXXX-XXXXX` (alphanumeric). Codes SHALL be hashed with bcrypt before storage. The encrypted JSON array of hashed codes SHALL be stored in `two_factor_recovery_codes`.

#### Scenario: Recovery codes generated at setup
- **WHEN** 2FA is successfully confirmed
- **THEN** 8 recovery codes are generated and returned to the user in plaintext
- **AND** the hashed versions are stored encrypted in the database

#### Scenario: Recovery code format
- **WHEN** recovery codes are generated
- **THEN** each code SHALL match the pattern `[A-Za-z0-9]{5}-[A-Za-z0-9]{5}`

### Requirement: Recovery code verification
The system SHALL accept a valid recovery code in place of a TOTP code. Upon successful use, the recovery code SHALL be removed from the stored set.

#### Scenario: Valid recovery code
- **WHEN** a user submits a valid, unused recovery code
- **THEN** the system accepts the code as valid
- **AND** removes the used code from the stored set (reducing available codes by 1)

#### Scenario: Already-used recovery code
- **WHEN** a user submits a recovery code that was previously used
- **THEN** the system rejects the code as invalid

#### Scenario: All recovery codes exhausted
- **WHEN** a user has used all 8 recovery codes
- **THEN** no recovery codes are accepted and the user must contact an admin for 2FA reset

### Requirement: Disable 2FA (self-service)
The system SHALL allow a user with active 2FA to disable it. Disabling SHALL null out `two_factor_secret`, `two_factor_confirmed_at`, and `two_factor_recovery_codes`.

#### Scenario: User disables their own 2FA
- **WHEN** an authenticated user with active 2FA requests to disable it
- **THEN** all three 2FA columns are set to null
- **AND** the system confirms 2FA has been disabled

#### Scenario: User without 2FA attempts to disable
- **WHEN** a user without active 2FA requests to disable it
- **THEN** the system rejects with an error indicating 2FA is not active

### Requirement: Admin reset of user's 2FA
The system SHALL allow users with user management permissions to reset another user's 2FA. This nulls all three 2FA columns, requiring the user to set up again.

#### Scenario: Admin resets a user's 2FA
- **WHEN** an admin resets 2FA for a user with active 2FA
- **THEN** all three 2FA columns are set to null for that user

#### Scenario: Admin resets 2FA for user without 2FA
- **WHEN** an admin attempts to reset 2FA for a user without active 2FA
- **THEN** the system rejects or no-ops with appropriate feedback

### Requirement: User model exposes 2FA status
The User model SHALL provide a `hasTwoFactorEnabled()` method that returns `true` when `two_factor_confirmed_at` is not null.

#### Scenario: User with confirmed 2FA
- **WHEN** `hasTwoFactorEnabled()` is called on a user with a non-null `two_factor_confirmed_at`
- **THEN** it returns `true`

#### Scenario: User without 2FA
- **WHEN** `hasTwoFactorEnabled()` is called on a user with null `two_factor_confirmed_at`
- **THEN** it returns `false`
