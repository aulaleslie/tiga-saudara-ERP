## ADDED Requirements

### Requirement: Authorized users can update only a completed purchase note
The normal setting-scoped purchase detail page SHALL provide an inline note editor for a non-archived purchase to users with `purchases.update`, including purchases in fully or partially received status. The editor SHALL validate and update only the purchase `note` field.

#### Scenario: Authorized user updates note on a fully received purchase
- **WHEN** a user with `purchases.update` opens a fully received non-archived purchase in the active setting and saves a valid note
- **THEN** the system SHALL persist the new note
- **AND** it SHALL not alter purchase details, totals, supplier, status, receiving records, or stock

#### Scenario: Unauthorized, archived, or foreign-setting purchase cannot be edited
- **WHEN** a user lacks `purchases.update`, the purchase is archived, or the purchase belongs to another setting
- **THEN** the note editor SHALL not permit an update
- **AND** the request SHALL be denied without modifying the note
