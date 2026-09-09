## ADDED Requirements

### Requirement: Successful switches emit diagnostic context
The system SHALL emit a privacy-safe `business_context_switched` event for every successful authorized active-business switch without changing the switch's authorization, role-refresh, or Home redirect behavior.

#### Scenario: Authorized user changes active business
- **WHEN** an authenticated user successfully switches from one authorized business to another
- **THEN** the system records the user ID, old business ID, new business ID, and safe session fingerprint
- **AND** continues to redirect the user to Home under the existing behavior

#### Scenario: Switch event logging fails
- **WHEN** emitting the business-switch diagnostic event fails
- **THEN** the authorized business switch still completes according to the existing behavior

### Requirement: Denied switch diagnostics preserve non-disclosure
The system MAY record a privacy-safe diagnostic event for a denied active-business switch, but any such instrumentation MUST NOT change the response or disclose whether the submitted business exists.

#### Scenario: Inaccessible or unknown business is submitted
- **WHEN** an authenticated user submits a business that is inaccessible or does not exist
- **THEN** the response and retained active context remain identical for both cases
- **AND** any diagnostic event does not expose the distinction to the user
