## ADDED Requirements

### Requirement: Purchase and sale lists expose a dedicated note column
The system SHALL render purchase and sale header notes in a dedicated `Catatan` column immediately after the `Ref` column in normal document lists, standalone Global Payment lists, and customer- or supplier-embedded Global Payment lists.

#### Scenario: Normal purchase and sale lists use the shared column order
- **WHEN** a user opens a normal Purchase or Sales list
- **THEN** the table displays `Catatan` immediately after `Ref`
- **AND** the reference cell does not contain the document header note

#### Scenario: Global Payment lists use the shared column order
- **WHEN** an authorized user opens a standalone or embedded Purchase or Sales Global Payment list
- **THEN** the table displays `Catatan` immediately after `Ref`
- **AND** the reference cell does not contain the document header note

#### Scenario: Blank note preserves table structure
- **WHEN** a listed purchase or sale has a null, empty, or whitespace-only header note
- **THEN** its `Catatan` cell displays `-`

### Requirement: Document list notes remain compact and readable
The system SHALL use one shared, escaped note presentation for Purchase and Sales list cells that preserves authored line breaks, wraps long content within a bounded column, and provides row-local expansion for notes exceeding the configured compact character or logical-line limit.

#### Scenario: Short note remains directly readable
- **WHEN** a non-blank document header note fits within the compact character and logical-line limits
- **THEN** the complete escaped note is displayed in the `Catatan` cell
- **AND** no expansion control is displayed

#### Scenario: Long or multiline note expands within its row
- **WHEN** a document header note exceeds the compact character or logical-line limit
- **THEN** the `Catatan` cell initially displays a compact preview and an accessible `Lihat selengkapnya` control
- **AND** activating the control displays the complete note and a `Tampilkan lebih sedikit` control
- **AND** expanding or collapsing the note does not alter expansion state in another row

#### Scenario: Note content is safely bounded
- **WHEN** a document header note contains line breaks, long unbroken text, or HTML-like characters
- **THEN** authored line breaks are rendered as visual line breaks
- **AND** long content wraps without forcing the surrounding table wider
- **AND** HTML-like characters remain escaped in preview and expanded content

