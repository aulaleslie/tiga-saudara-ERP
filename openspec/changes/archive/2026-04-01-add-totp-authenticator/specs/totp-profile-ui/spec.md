## ADDED Requirements

### Requirement: Profile page 2FA section — not set up state
The profile page SHALL display a 2FA card below the existing profile and password cards. When the user has no active 2FA, the card SHALL show a message indicating 2FA is not enabled and an "Aktifkan Authenticator" button.

#### Scenario: User without 2FA views profile
- **WHEN** a user without active 2FA views their profile page
- **THEN** a 2FA card is displayed with status "2FA belum diaktifkan"
- **AND** an "Aktifkan Authenticator" button is shown

### Requirement: Profile page 2FA section — setup in progress state
When the user clicks "Aktifkan Authenticator", the card SHALL display the QR code, manual entry key, and a 6-digit code input field with a "Konfirmasi" button. This transition SHALL happen via AJAX without page reload.

#### Scenario: User initiates setup
- **WHEN** the user clicks "Aktifkan Authenticator"
- **THEN** the system calls the setup endpoint via AJAX
- **AND** the card updates to show the QR code as inline SVG
- **AND** the manual entry key is displayed as formatted text
- **AND** a 6-digit input field and "Konfirmasi" button are shown
- **AND** instructions to install an authenticator app are displayed

#### Scenario: User confirms setup with valid code
- **WHEN** the user enters a valid 6-digit code and clicks "Konfirmasi"
- **THEN** the system calls the confirm endpoint via AJAX
- **AND** the card transitions to the active state
- **AND** recovery codes are displayed with a prompt to save them

### Requirement: Recovery codes display after setup
After successful 2FA confirmation, the card SHALL display the 8 recovery codes with a warning to save them securely. A "Saya Sudah Menyimpan" button SHALL dismiss the recovery codes view and show the active state.

#### Scenario: Recovery codes shown after confirmation
- **WHEN** 2FA is successfully confirmed
- **THEN** recovery codes are displayed in a copyable format
- **AND** a warning message states each code is single-use
- **AND** a "Saya Sudah Menyimpan" button is shown

#### Scenario: User acknowledges saving codes
- **WHEN** the user clicks "Saya Sudah Menyimpan"
- **THEN** the card transitions to the active state with test and disable options

### Requirement: Profile page 2FA section — active state
When the user has active 2FA, the card SHALL show confirmation that 2FA is enabled, a "Uji Kode" (test code) input with verify button, and a "Nonaktifkan 2FA" button.

#### Scenario: User with active 2FA views profile
- **WHEN** a user with active 2FA views their profile page
- **THEN** a 2FA card shows "2FA aktif" with the confirmed date
- **AND** a test code input field and "Verifikasi" button are shown
- **AND** a "Nonaktifkan 2FA" button is shown

### Requirement: Test TOTP code from profile
The active state SHALL include a test input where the user can enter a 6-digit code and receive immediate feedback on whether the code is valid. This verifies the authenticator is working without gating any action.

#### Scenario: Valid test code
- **WHEN** the user enters a valid 6-digit code and clicks "Verifikasi"
- **THEN** the system calls the test endpoint via AJAX
- **AND** a success indicator is shown inline (e.g., "Kode valid!")

#### Scenario: Invalid test code
- **WHEN** the user enters an invalid 6-digit code and clicks "Verifikasi"
- **THEN** the system calls the test endpoint via AJAX
- **AND** an error indicator is shown inline (e.g., "Kode tidak valid")

### Requirement: Disable 2FA from profile
The profile 2FA card SHALL include a "Nonaktifkan 2FA" button that disables 2FA via AJAX and transitions the card back to the not-set-up state.

#### Scenario: User disables 2FA
- **WHEN** the user clicks "Nonaktifkan 2FA"
- **THEN** the system calls the disable endpoint via AJAX (DELETE)
- **AND** the card transitions back to the not-set-up state
- **AND** a confirmation message is shown

### Requirement: Admin user edit page shows 2FA status
The user edit page SHALL display the user's 2FA status. If 2FA is active, a "Reset 2FA" button SHALL be shown.

#### Scenario: Admin views user with active 2FA
- **WHEN** an admin views the edit page for a user with active 2FA
- **THEN** the 2FA section shows "2FA aktif" with the confirmed date
- **AND** a "Reset 2FA" button is displayed

#### Scenario: Admin views user without 2FA
- **WHEN** an admin views the edit page for a user without active 2FA
- **THEN** the 2FA section shows "2FA tidak aktif"
- **AND** no reset button is displayed

#### Scenario: Admin resets user's 2FA
- **WHEN** an admin clicks "Reset 2FA" for a user
- **THEN** the system resets the user's 2FA
- **AND** the section updates to show "2FA tidak aktif"

### Requirement: All 2FA interactions are AJAX
All profile 2FA operations (setup, confirm, test, disable) SHALL use AJAX requests and update the card inline. No page reloads SHALL occur for 2FA interactions. Existing profile update and password change forms SHALL remain untouched.

#### Scenario: Page does not reload during 2FA operations
- **WHEN** the user performs any 2FA operation (setup, confirm, test, disable)
- **THEN** the operation completes via AJAX
- **AND** the page does not reload
- **AND** the 2FA card updates to reflect the new state
