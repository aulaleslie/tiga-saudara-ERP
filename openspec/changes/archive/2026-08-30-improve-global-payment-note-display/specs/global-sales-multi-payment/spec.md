## MODIFIED Requirements

### Requirement: Global sales document notes and allocation priority
The system SHALL expose escaped sale transaction header notes beside their document identity in Global Payment contexts, SHALL present list notes in a compact and individually expandable form that preserves authored line breaks, and SHALL present payment candidates in entry-prioritized due-date order.

#### Scenario: Sale note is visible beneath the document number
- **WHEN** an eligible sale with a non-blank header note is rendered in a standalone or customer-embedded Global Payment list or in the sales allocation form
- **THEN** the escaped note is displayed directly beneath that sale's document number
- **AND** a blank note adds no placeholder beneath the document number

#### Scenario: Short sale note remains directly readable
- **WHEN** a sale header note fits within the configured compact preview
- **THEN** the Global Payment list displays the complete escaped note without an expansion control

#### Scenario: Long or multiline sale note can be expanded locally
- **WHEN** a sale header note exceeds the configured compact character or line limit in a Global Payment list
- **THEN** the list initially displays only the beginning of that note
- **AND** an accessible `Lihat selengkapnya` control expands the complete note for that sale row
- **AND** the expanded note can be collapsed with a `Tampilkan lebih sedikit` control
- **AND** expanding or collapsing one row does not change the presentation of notes in other rows

#### Scenario: Sale note formatting is preserved safely
- **WHEN** a displayed sale header note contains line breaks, long unbroken text, or HTML-like characters
- **THEN** authored line breaks are rendered as visual line breaks
- **AND** long text wraps without forcing the Global Payment table wider
- **AND** HTML-like characters remain escaped in both preview and expanded content

#### Scenario: Global sales list search matches the header note
- **WHEN** a user searches a standalone or customer-embedded Global Payment list for text contained only in an eligible sale's header note
- **THEN** that sale is included in the results using the complete stored note
- **AND** its matching note is visible beneath its document number

#### Scenario: Entry sale remains the first allocation candidate
- **WHEN** an authorized user opens the allocation form from an eligible sale
- **THEN** that entry sale is the first allocation row regardless of its due date or identifier
- **AND** it retains the existing default allocation of its full live outstanding balance

#### Scenario: Remaining sales use deterministic due-date order
- **WHEN** the sales allocation form contains candidates other than the entry sale
- **THEN** dated remaining candidates are ordered by due date ascending
- **AND** candidates with the same due date are ordered by identifier ascending
- **AND** candidates without a due date are placed after dated candidates and ordered by identifier ascending
