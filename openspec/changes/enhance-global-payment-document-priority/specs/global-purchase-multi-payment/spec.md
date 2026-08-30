## ADDED Requirements

### Requirement: Global purchase document notes and allocation priority
The system SHALL expose purchase transaction header notes beside their document identity in Global Payment contexts and SHALL present payment candidates in entry-prioritized due-date order.

#### Scenario: Purchase note is visible beneath the document number
- **WHEN** an eligible purchase with a non-blank header note is rendered in a standalone or supplier-embedded Global Payment list or in the purchase allocation form
- **THEN** the escaped note is displayed directly beneath that purchase's document number
- **AND** a blank note adds no placeholder beneath the document number

#### Scenario: Global purchase list search matches the header note
- **WHEN** a user searches a standalone or supplier-embedded Global Payment list for text contained only in an eligible purchase's header note
- **THEN** that purchase is included in the results
- **AND** its matching note is visible beneath its document number

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
