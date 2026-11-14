# Spec Delta

## ADDED Requirements

### Requirement: Independent global POS summary loading
Standalone and customer-embedded global POS payment workspaces SHALL render their table and summary placeholders without waiting for initial summary projection. Summaries SHALL load automatically with the applied customer, business, transaction-date, due-date, and card-selection state while retaining existing authorization.

#### Scenario: Initial projection is slow
- **WHEN** an authorized user opens a global POS workspace
- **THEN** initial rendering does not calculate summary projections
- **AND** a visible placeholder remains until automatic summary loading completes
- **AND** unknown totals are not displayed as zero
- **AND** the existing table results and settlement projection remain unchanged

#### Scenario: Customer workspace initializes asynchronously
- **WHEN** deferred summaries initialize on a customer detail page
- **THEN** summary results remain restricted to that immutable customer across selected businesses
- **AND** unauthorized users cannot obtain summary data through the deferred action

### Requirement: Global POS loading feedback covers summary and table refreshes
The global POS workspace SHALL track summary and table pending states independently, including summary filter-event handling and the complete card-to-table filtering sequence. Failures SHALL release the relevant pending state and provide recovery without hiding unrelated work.

#### Scenario: Summary scope changes
- **WHEN** Apply Filter changes summary inputs or Reset forces fresh totals
- **THEN** summary pending feedback covers the recalculation until completion or failure
- **AND** table feedback covers its own refresh
- **AND** both regions settle on the latest applied inputs without changing existing filter or summary semantics

#### Scenario: Card selection completes or fails
- **WHEN** a card is selected or deselected
- **THEN** table feedback starts on selection and ends after its resulting update completes or fails
- **AND** unchanged summary totals are not recalculated solely for card selection

#### Scenario: Summary failure can be retried
- **WHEN** automatic summary loading fails
- **THEN** a visible summary error and retry action replace its pending state
- **AND** retry calculates current totals using the latest applied inputs
- **AND** table interactions remain available
