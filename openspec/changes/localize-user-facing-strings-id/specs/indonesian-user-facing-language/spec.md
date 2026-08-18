## ADDED Requirements

### Requirement: Status protocol values and styling are excluded from translation
The system SHALL treat stored status values, enum constants, status comparison literals, and CSS class names as protocol, and SHALL NOT translate, rename, or reformat them when localizing user-facing text.

#### Scenario: Status branch condition is unchanged by localization
- **WHEN** a status partial is localized to display Indonesian text
- **THEN** every `@if` and `@elseif` comparison literal in that partial SHALL remain byte-identical to its pre-localization value

#### Scenario: Badge styling is unchanged by localization
- **WHEN** a status partial is localized to display Indonesian text
- **THEN** every CSS class token applied to the status badge SHALL remain byte-identical to its pre-localization value, so the badge renders in the same colour as before

#### Scenario: Stored status values are never rewritten
- **WHEN** user-facing status text is localized
- **THEN** the system SHALL NOT modify any status value persisted in the database, and SHALL NOT modify any status enum constant's value

#### Scenario: Status filtering continues to match stored values
- **WHEN** a user filters a list by status after localization
- **THEN** the filter SHALL match against the stored protocol value and SHALL return the same rows it returned before localization

### Requirement: User-facing status text is displayed in Indonesian via a label lookup
The system SHALL render status text to users in Indonesian by resolving the stored status value through a label map defined on the owning entity, rather than echoing the stored value directly.

#### Scenario: A mapped status renders its Indonesian label
- **WHEN** a status badge is rendered for a status value present in the owning entity's label map
- **THEN** the badge SHALL display the Indonesian label from that map

#### Scenario: An unmapped status degrades to the raw value
- **WHEN** a status badge is rendered for a status value that has no entry in the label map
- **THEN** the badge SHALL display the raw stored value rather than rendering empty text

#### Scenario: Client-rendered status badges use the same labels
- **WHEN** a status badge is rendered by client-side JavaScript rather than by Blade
- **THEN** the displayed text SHALL be resolved through the same label definitions used server-side, while the value passed to the badge-class selector SHALL remain the raw stored value

### Requirement: Validation errors are fully Indonesian, including field names
The system SHALL present validation error messages to users entirely in Indonesian, including the field name interpolated into the message.

#### Scenario: A field name appears in Indonesian
- **WHEN** a validation rule fails for a field whose key is an English or snake_case column name
- **THEN** the error message SHALL name that field in Indonesian rather than displaying the raw field key

#### Scenario: No rule message remains in English
- **WHEN** any validation rule fails
- **THEN** the resulting message text SHALL be in Indonesian

### Requirement: Column headers and form labels are displayed in Indonesian
The system SHALL display list column headers, form field labels, readonly field labels, and help text in Indonesian.

#### Scenario: A list column header is Indonesian
- **WHEN** a list view renders its column headers
- **THEN** each user-facing header SHALL be in Indonesian

#### Scenario: A readonly or disabled field is labelled in Indonesian
- **WHEN** a form renders a field the user cannot edit
- **THEN** that field's label SHALL be in Indonesian, consistent with editable fields on the same form

### Requirement: Internal surrogate identifiers are not presented as business values
The system SHALL NOT display internal database surrogate keys as user-facing values on business screens where a human-readable identifier exists on the same record.

#### Scenario: A human-readable handle replaces a surrogate key
- **WHEN** a business screen would display a record's surrogate key and that record carries a human-readable identifier such as a product code or document reference
- **THEN** the screen SHALL display the human-readable identifier instead

#### Scenario: Diagnostic surfaces retain identifiers with Indonesian labels
- **WHEN** an audit, diagnostic, or import-batch surface displays surrogate keys as operator handles
- **THEN** the system SHALL continue to display those identifiers, and SHALL label them in Indonesian

### Requirement: Localization does not alter behaviour
The system SHALL confine localization changes to the presentation layer, leaving stored data, routes, API payloads, and export contracts unchanged.

#### Scenario: Exports remain consistent with filters
- **WHEN** a list is exported after localization
- **THEN** the exported status representation SHALL remain consistent with the values that list's filters match, so that filtering and exporting cannot disagree

#### Scenario: No API payload changes
- **WHEN** an API or integration consumer reads a record after localization
- **THEN** the payload's status values SHALL be identical to those returned before localization
