## ADDED Requirements

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
