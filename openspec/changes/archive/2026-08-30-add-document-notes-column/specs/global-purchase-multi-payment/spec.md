## MODIFIED Requirements

### Requirement: Global purchase document notes and allocation priority
The system SHALL expose escaped purchase transaction header notes in Global Payment contexts, SHALL present list notes in a dedicated `Catatan` column immediately after `Ref` using compact and individually expandable presentation, and SHALL present payment candidates in entry-prioritized due-date order.

#### Scenario: Purchase note is visible in the list note column
- **WHEN** an eligible purchase is rendered in a standalone or supplier-embedded Global Payment list
- **THEN** its escaped header note is displayed in the dedicated `Catatan` column immediately after `Ref`
- **AND** the reference cell does not contain the header note
- **AND** a blank note displays `-` in the `Catatan` cell

#### Scenario: Purchase allocation note remains associated with document identity
- **WHEN** an eligible purchase with a non-blank header note is rendered in the purchase allocation form
- **THEN** the escaped note remains displayed with that purchase's document identity
- **AND** a blank note adds no placeholder beneath the document number

#### Scenario: Short purchase note remains directly readable
- **WHEN** a purchase header note fits within the configured compact preview
- **THEN** the Global Payment list displays the complete escaped note without an expansion control

#### Scenario: Long or multiline purchase note can be expanded locally
- **WHEN** a purchase header note exceeds the configured compact character or line limit in a Global Payment list
- **THEN** the list initially displays only the beginning of that note
- **AND** an accessible `Lihat selengkapnya` control expands the complete note for that purchase row
- **AND** the expanded note can be collapsed with a `Tampilkan lebih sedikit` control
- **AND** expanding or collapsing one row does not change the presentation of notes in other rows

#### Scenario: Purchase note formatting is preserved safely
- **WHEN** a displayed purchase header note contains line breaks, long unbroken text, or HTML-like characters
- **THEN** authored line breaks are rendered as visual line breaks
- **AND** long text wraps without forcing the Global Payment table wider
- **AND** HTML-like characters remain escaped in both preview and expanded content

#### Scenario: Global purchase list search matches the header note
- **WHEN** a user searches a standalone or supplier-embedded Global Payment list for text contained only in an eligible purchase's header note
- **THEN** that purchase is included in the results using the complete stored note
- **AND** its matching note is visible in the dedicated `Catatan` column

#### Scenario: Entry purchase remains the first allocation candidate
- **WHEN** an authorized user opens the allocation form from an eligible purchase
- **THEN** that entry purchase is the first allocation row regardless of its due date or identifier
- **AND** it retains the existing default allocation of its full live outstanding balance

#### Scenario: Remaining purchases use deterministic due-date order
- **WHEN** the purchase allocation form contains candidates other than the entry purchase
- **THEN** remaining candidates are ordered by due date ascending
- **AND** candidates with the same due date are ordered by identifier ascending

#### Scenario: Supplier entry without a purchase uses due-date order
- **WHEN** an authorized user opens the purchase allocation form from supplier context without an entry purchase
- **THEN** candidates are ordered by due date ascending
- **AND** candidates with the same due date use identifier ascending

