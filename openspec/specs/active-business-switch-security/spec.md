# active-business-switch-security Specification

## Purpose
Secure active-business switching by enforcing server-side authorization before mutating session context, and redirect successful switches to Home to avoid stale scoped records and 404 errors.
## Requirements
### Requirement: Authorized active-business selection
The system SHALL authorize every active-business switch request against the submitted business before changing the authenticated user's business context. A Super Admin SHALL be authorized for any existing business. Any other authenticated user SHALL be authorized only when that user has an assignment to the submitted business in `user_setting`.

#### Scenario: Assigned user switches business
- **WHEN** a non-Super-Admin user submits an existing business assigned to that user
- **THEN** the system sets that business as the active business and refreshes the user's context for it

#### Scenario: Super Admin switches to an unassigned business
- **WHEN** a Super Admin submits an existing business with no `user_setting` assignment for that user
- **THEN** the system accepts the switch and refreshes the active-business context

### Requirement: Denied switches preserve the active context
The system SHALL reject a submitted business that does not exist or is inaccessible to the authenticated user without changing the active-business session value, settings cache, or active role context. The denial response SHALL NOT reveal whether the submitted business exists.

#### Scenario: Standard user submits another user's business
- **WHEN** a non-Super-Admin user submits an existing business to which they are not assigned
- **THEN** the system denies the request and retains the previously active business context

#### Scenario: User submits an unknown business
- **WHEN** an authenticated user submits a business identifier that does not exist
- **THEN** the system denies the request and retains the previously active business context without disclosing whether the identifier exists

### Requirement: Successful switching starts at Home
The system SHALL redirect every successful active-business switch to the named Home route instead of returning the user to the request referrer.

#### Scenario: User switches from a scoped document page
- **WHEN** an authorized user changes business while their referrer is a page scoped to the previous business
- **THEN** the response redirects to Home and does not request the previous scoped page

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

