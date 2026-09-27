# Spec Delta

## Purpose

Ensure operational status information is presented consistently in Bahasa Indonesia across transaction, return, payment, reporting, export, and POS interfaces without altering canonical workflow values.

## ADDED Requirements

### Requirement: Indonesian user-facing status labels
The system SHALL display Indonesian labels instead of raw English status values on user-facing purchase, sales, sales return, purchase return, global payment, POS transaction, and POS return interfaces.

#### Scenario: Document status appears in a list or detail view
- **WHEN** a user views a purchase, sale, return, payment, or POS document status
- **THEN** the displayed status is an Indonesian label rather than the canonical English value

#### Scenario: Status appears in a report or export
- **WHEN** an affected status is rendered in an on-screen report, printable output, or exported user-facing dataset
- **THEN** the output uses the same Indonesian meaning as the corresponding operational screen

### Requirement: Context-specific partial-state terminology
The system SHALL translate partial states according to the business action represented by the status.

#### Scenario: Partially dispatched document
- **WHEN** a document has a partially dispatched status
- **THEN** the system displays `Dikirim Sebagian`

#### Scenario: Partially received document
- **WHEN** a document has a partially received status
- **THEN** the system displays `Diterima Sebagian`

#### Scenario: Partially returned document
- **WHEN** a document has a partially returned status
- **THEN** the system displays `Dikembalikan Sebagian`

#### Scenario: Partially paid document
- **WHEN** a document has a partially paid payment status
- **THEN** the system displays `Dibayar Sebagian`

#### Scenario: Partially settled return
- **WHEN** a return has a partially completed settlement status
- **THEN** the system displays `Diselesaikan Sebagian`

### Requirement: Consistent lifecycle terminology
The system SHALL use consistent Indonesian terminology for equivalent lifecycle, approval, payment, and settlement states across affected modules.

#### Scenario: Equivalent status appears on different surfaces
- **WHEN** the same canonical status is shown in a list, detail, filter, embedded view, report, or export
- **THEN** each surface displays the same context-appropriate Indonesian label

#### Scenario: Draft status is displayed
- **WHEN** an affected document is in a draft state
- **THEN** the system displays `Draf`

### Requirement: Canonical status compatibility
The system MUST preserve canonical persisted status values and MUST continue using those values for workflow decisions, filtering, querying, integrations, and API behavior.

#### Scenario: A translated status is rendered
- **WHEN** an Indonesian status label is shown to a user
- **THEN** the underlying stored value remains unchanged

#### Scenario: A user filters by a translated status option
- **WHEN** a user selects an Indonesian status label in an affected filter
- **THEN** the system queries using the corresponding canonical status value

### Requirement: Indonesian labels on affected global payment controls
The system SHALL display Indonesian headings and pagination/action labels in the global purchase and sales payment workspaces where those labels are part of the affected transaction tables.

#### Scenario: User opens a global payment workspace
- **WHEN** a user views the global purchase or sales payment table
- **THEN** its affected headings and navigation controls are presented in Indonesian
