## ADDED Requirements

### Requirement: Global sales document notes and allocation priority
The system SHALL expose sale transaction header notes beside their document identity in Global Payment contexts and SHALL present payment candidates in entry-prioritized due-date order.

#### Scenario: Sale note is visible beneath the document number
- **WHEN** an eligible sale with a non-blank header note is rendered in a standalone or customer-embedded Global Payment list or in the sales allocation form
- **THEN** the escaped note is displayed directly beneath that sale's document number
- **AND** a blank note adds no placeholder beneath the document number

#### Scenario: Global sales list search matches the header note
- **WHEN** a user searches a standalone or customer-embedded Global Payment list for text contained only in an eligible sale's header note
- **THEN** that sale is included in the results
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
