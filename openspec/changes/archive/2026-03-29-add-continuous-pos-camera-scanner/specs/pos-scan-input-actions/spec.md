## MODIFIED Requirements

### Requirement: POS camera scan session SHALL remain open across accepted scans until cashier exits
The camera scan modal MUST remain open after successful, not-found, and retryable error outcomes so tablet cashiers can continue scanning multiple products in sequence. The session MUST stop camera stream and close only when the cashier explicitly exits the scanner or the session cannot continue due to unrecoverable camera initialization failure.

#### Scenario: Successful decode keeps scanner session active
- **WHEN** a decoded value resolves to a product or serial match during an active camera scan session
- **THEN** the system MUST complete the existing success flow, keep the scanner modal open, and return the session to a ready state for the next scan

#### Scenario: Not-found outcome stays in session for immediate retry
- **WHEN** a decoded value resolves as not found during an active camera scan session
- **THEN** the system MUST keep the scanner modal open, preserve the decoded value in the existing scan input, show not-found guidance, and allow the cashier to continue scanning without reopening the scanner

#### Scenario: Cashier-controlled exit ends the session
- **WHEN** the cashier activates the scanner close action
- **THEN** the system MUST stop decode processing, release the camera stream, and close the scanner modal

## ADDED Requirements

### Requirement: POS continuous camera scanning SHALL emulate discrete hardware scanner submissions
During an active camera scan session, the system MUST accept only one decoded value at a time for resolver submission, MUST suppress duplicate submissions caused by repeated frame detections, and MUST automatically re-arm the session after each accepted scan outcome so the camera behaves like a virtual scanner device.

#### Scenario: In-flight submission blocks additional accepted scans
- **WHEN** a decoded value has been accepted and the shared scan resolver is still processing it
- **THEN** the system MUST ignore further decode hits until the current resolver submission finishes

#### Scenario: Same code is not re-submitted immediately from repeated frames
- **WHEN** the same decoded value appears repeatedly within the configured duplicate-suppression window
- **THEN** the system MUST treat those repeated detections as one accepted scan and MUST NOT submit duplicate resolver requests for that value

#### Scenario: Different next item can be scanned after re-arm
- **WHEN** a prior accepted scan outcome completes and the short re-arm cooldown elapses
- **THEN** the system MUST return the session to a ready scanning state so the next distinct product barcode can be accepted without reopening the scanner

### Requirement: POS continuous camera scan session SHALL provide persistent in-session guidance and outcome feedback
The mobile camera scanner surface MUST show scanner readiness, accepted-scan feedback, warnings, and retry guidance inside the active session so cashiers can keep working without relying on modal churn for feedback. The scanner surface MUST include a visible scan guide appropriate for repeated barcode scanning on tablets.

#### Scenario: Ready state guides cashier aim
- **WHEN** the camera preview becomes ready for scanning
- **THEN** the system MUST show in-session guidance indicating that the cashier can aim a barcode within the scan guide and begin scanning immediately

#### Scenario: Accepted scan feedback is shown without closing session
- **WHEN** a decoded value is accepted for resolver submission
- **THEN** the scanner surface MUST show immediate in-session feedback that the scan was captured while keeping the session active

#### Scenario: Retryable failure is communicated in session
- **WHEN** the scanner encounters a retryable decode, not-found, or resolver failure during an active session
- **THEN** the system MUST show the warning or error state inside the scanner session and allow the cashier to retry without reopening the scanner
