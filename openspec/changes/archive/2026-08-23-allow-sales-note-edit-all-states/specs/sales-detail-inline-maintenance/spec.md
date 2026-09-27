## ADDED Requirements

### Requirement: Authorized users can update a sale note in every lifecycle state
The normal setting-scoped sales detail page SHALL provide an inline note editor for any non-archived sale to users with `sales.edit`, regardless of the sale lifecycle status. The editor SHALL validate and update only the sale `note` field and SHALL NOT require lifecycle-specific approved-sale or dispatched-sale edit permissions.

#### Scenario: Authorized user updates a note in any lifecycle state
- **WHEN** a user with `sales.edit` opens a non-archived sale in the active setting whose status is drafted, waiting approval, approved, rejected, partially dispatched, dispatched, partially returned, or returned and saves a valid note
- **THEN** the system SHALL persist the new note
- **AND** it SHALL leave the sale status, customer, details, totals, dispatches, payments, returns, stock, and all other fields and related records unchanged

#### Scenario: Lifecycle-specific edit permission is not required
- **WHEN** a user has `sales.edit` but lacks `sales.approved.edit` and `sales.dispatched.monetary.edit`
- **AND** the user saves a valid note on an approved, partially dispatched, dispatched, partially returned, or returned sale
- **THEN** the system SHALL permit the note update
- **AND** it SHALL not expose broader full-document or monetary editing through the note editor

### Requirement: Sale note updates preserve authorization and business boundaries
The inline editor SHALL reauthorize every edit and save operation, require `sales.edit`, reject archived sales, and require the sale to belong to the active setting. Users who cannot edit SHALL still see the current note as read-only on a detail page they are authorized to view.

#### Scenario: User without sales edit permission views the note
- **WHEN** a user can view a setting-scoped sale but lacks `sales.edit`
- **THEN** the detail page SHALL display the current note without edit controls
- **AND** an attempted note mutation SHALL be denied without modifying the sale

#### Scenario: Archived sale cannot be modified
- **WHEN** a user with `sales.edit` attempts to edit the note of an archived sale
- **THEN** the system SHALL deny the operation
- **AND** it SHALL not modify the sale note

#### Scenario: Foreign-setting sale cannot be modified
- **WHEN** a user attempts to load or save the note editor for a sale outside the active setting
- **THEN** the system SHALL respond as though that sale is not available in the current setting
- **AND** it SHALL not modify the sale note

#### Scenario: Global sales detail remains read-only
- **WHEN** a sale is displayed through a global or cross-business detail view
- **THEN** the system SHALL display the note without the setting-scoped inline editor

### Requirement: Sale note input is validated and reversible before saving
The inline editor SHALL accept a nullable string of at most 1,000 characters, normalize an empty string to `null`, and allow the user to cancel an in-progress edit without persisting it.

#### Scenario: Valid note is saved
- **WHEN** an authorized user saves a note containing no more than 1,000 characters
- **THEN** the system SHALL persist the value and display a success notification

#### Scenario: Empty note is normalized
- **WHEN** an authorized user saves an empty note
- **THEN** the system SHALL persist the sale note as `null`

#### Scenario: Oversized note is rejected
- **WHEN** an authorized user attempts to save a note longer than 1,000 characters
- **THEN** the system SHALL show a validation error
- **AND** it SHALL preserve the previously stored note

#### Scenario: User cancels an edit
- **WHEN** a user changes the note in the editor and selects cancel before saving
- **THEN** the editor SHALL restore the currently persisted note
- **AND** it SHALL not write a change to the sale
