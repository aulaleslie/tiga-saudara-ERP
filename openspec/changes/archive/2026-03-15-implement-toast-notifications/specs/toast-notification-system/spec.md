## ADDED Requirements

### Requirement: Global toast notification helper function
The system SHALL provide a global JavaScript function `showToast(message, type, duration)` that displays non-blocking toast notifications to the user. This function SHALL wrap SweetAlert2's toast functionality and provide a consistent, reusable API across all pages.

#### Scenario: Display success toast
- **WHEN** `showToast("Operation successful", "success")` is called
- **THEN** a green toast notification appears in the top-right corner with a checkmark icon, displays the message, and auto-closes after 2 seconds

#### Scenario: Display error toast
- **WHEN** `showToast("Operation failed", "error")` is called
- **THEN** a red toast notification appears in the top-right corner with an error icon, displays the message, and auto-closes after 2 seconds

#### Scenario: Display toast with custom duration
- **WHEN** `showToast("Custom message", "success", 4000)` is called
- **THEN** the toast displays the message and auto-closes after 4 seconds

#### Scenario: Default duration
- **WHEN** `showToast("Message", "success")` is called without specifying duration
- **THEN** the toast auto-closes after 2 seconds (default duration)

### Requirement: Toast notification types
The toast notification system SHALL support multiple notification types: success, error, warning, and info. Each type SHALL have an appropriate icon and color.

#### Scenario: Success toast styling
- **WHEN** a success toast is displayed
- **THEN** it shows a green background with a checkmark icon

#### Scenario: Error toast styling
- **WHEN** an error toast is displayed
- **THEN** it shows a red background with an error/x icon

#### Scenario: Warning toast styling
- **WHEN** a warning toast is displayed
- **THEN** it shows a yellow/orange background with a warning icon

#### Scenario: Info toast styling
- **WHEN** an info toast is displayed
- **THEN** it shows a blue background with an info icon

### Requirement: Toast notifications in approval queue
The POS approval queue page SHALL display toast notifications instead of native browser alerts for approval and rejection feedback. When a user approves or rejects a request, the system SHALL display an auto-closing toast with the result message.

#### Scenario: Successful approval toast
- **WHEN** user submits an approval in the approval queue
- **THEN** a success toast displays "Persetujuan berhasil disimpan." and auto-closes after 2 seconds

#### Scenario: Failed approval toast
- **WHEN** user attempts approval but the request fails
- **THEN** an error toast displays the error message (e.g., "Gagal menyetujui: [error details]") and auto-closes after 2 seconds

#### Scenario: Successful rejection toast
- **WHEN** user submits a rejection in the approval queue
- **THEN** a success toast displays "Penolakan berhasil disimpan." and auto-closes after 2 seconds

#### Scenario: Failed rejection toast
- **WHEN** user attempts rejection but the request fails
- **THEN** an error toast displays the error message (e.g., "Gagal menolak: [error details]") and auto-closes after 2 seconds

### Requirement: Toast positioning and behavior
Toast notifications SHALL appear in the top-right corner of the viewport, positioned above other content but below the page header. Toasts SHALL not require user interaction to dismiss (no confirm button) and SHALL display a progress bar indicating remaining time until auto-close.

#### Scenario: Toast appears in correct position
- **WHEN** a toast is displayed
- **THEN** it appears in the top-right corner of the screen, above other page content

#### Scenario: Toast has progress indicator
- **WHEN** a toast is displayed
- **THEN** a small progress bar at the bottom of the toast shows remaining time before auto-close

#### Scenario: Toast does not require confirmation
- **WHEN** a toast is displayed
- **THEN** no confirm button is shown; toast auto-closes when timer expires
