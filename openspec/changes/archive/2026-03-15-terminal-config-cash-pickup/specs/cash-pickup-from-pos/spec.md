## ADDED Requirements

### Requirement: Supervisor can initiate cash pickup from POS terminal menu
The system SHALL provide a "Pengambilan Kas" (Cash Pickup) option in the POS terminal's top-right navigation dropdown menu, accessible to all users but requiring supervisor credentials to execute.

#### Scenario: Menu item appears in dropdown
- **WHEN** user clicks the navigation menu dropdown on POS sell screen
- **THEN** "Pengambilan Kas" option appears in the dropdown list

#### Scenario: Clicking pickup initiates two-step modal
- **WHEN** user clicks "Pengambilan Kas"
- **THEN** a modal opens showing:
  - Current session terminal name/code
  - Cashier name
  - Current expected cash total in currency format
  - Input field for pickup amount
  - "Lanjut" (Next) button

### Requirement: Supervisor validates pickup amount
The system SHALL validate that the pickup amount is a positive number not exceeding the session's current expected cash total.

#### Scenario: Amount validation - valid amount
- **WHEN** supervisor enters amount between 1 and expected_cash_total and clicks "Lanjut"
- **THEN** modal transitions to step 2 (supervisor credentials)

#### Scenario: Amount validation - zero or negative
- **WHEN** supervisor enters 0 or negative number
- **THEN** error message displays: "Jumlah harus lebih dari 0"

#### Scenario: Amount validation - exceeds available cash
- **WHEN** supervisor enters amount greater than expected_cash_total
- **THEN** error message displays: "Jumlah tidak boleh melebihi kas yang diharapkan"

### Requirement: Supervisor authenticates using email and password
The system SHALL require supervisor email and password credentials before processing the cash pickup.

#### Scenario: Credentials step displays properly
- **WHEN** amount validation passes and "Lanjut" is clicked
- **THEN** modal shows step 2 with:
  - Confirmation of pickup amount in currency format
  - Email input field
  - Password input field (masked)
  - "Konfirmasi Pengambilan" button

#### Scenario: Credentials validation - missing email
- **WHEN** supervisor leaves email blank and clicks "Konfirmasi Pengambilan"
- **THEN** error message displays: "Email wajib diisi"

#### Scenario: Credentials validation - missing password
- **WHEN** supervisor leaves password blank and clicks "Konfirmasi Pengambilan"
- **THEN** error message displays: "Password wajib diisi"

### Requirement: System validates supervisor credentials and permissions
The system SHALL authenticate the supervisor using email and password, verify they belong to the setting, and confirm they have the `pos.safeDrops.approve` permission before approving the pickup.

#### Scenario: Valid credentials and permission
- **WHEN** supervisor enters valid email and password and has `pos.safeDrops.approve` permission
- **THEN** system calls the pickup API endpoint and proceeds to success

#### Scenario: Invalid credentials
- **WHEN** supervisor enters invalid email/password or user is inactive
- **THEN** error message displays: "Email atau password salah"

#### Scenario: Supervisor lacks required permission
- **WHEN** supervisor has valid credentials but lacks `pos.safeDrops.approve` permission
- **THEN** error message displays: "Anda tidak memiliki izin untuk melakukan pengambilan kas"

#### Scenario: Supervisor not assigned to current setting
- **WHEN** supervisor email belongs to user not assigned to current setting
- **THEN** error message displays: "Email atau password salah"

### Requirement: Cash pickup is processed and confirmed
The system SHALL create a safe drop cash event, update expected cash total, and display a success toast notification.

#### Scenario: Successful cash pickup
- **WHEN** supervisor credentials are valid and have required permission
- **THEN** system:
  - Creates a cash event of type SAFE_DROP_OUT
  - Reduces expected_cash_total by pickup amount
  - Displays success toast: "Pengambilan Kas Berhasil - Rp X,XXX,XXX telah diambil"
  - Shows updated expected_cash after pickup
  - Closes modal

#### Scenario: Drawer opens after successful pickup
- **WHEN** cash pickup succeeds
- **THEN** drawer opens (if terminal policy `auto_open_drawer_on_pickup` is enabled)

#### Scenario: Expected cash accurately updated
- **WHEN** cash pickup succeeds for amount A
- **THEN** expected_cash_after = expected_cash_before - A (rounded to 2 decimals)
