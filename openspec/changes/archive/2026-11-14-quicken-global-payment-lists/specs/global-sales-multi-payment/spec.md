# Spec Delta

## ADDED Requirements

### Requirement: Independent global Sales summary loading
The standalone and customer-embedded global Sales workspaces SHALL render their table and a summary placeholder without waiting for initial summary calculation. Summaries SHALL load automatically with visible pending, completion, and recoverable failure states, preserving authorization and the applied customer, business, date, and selection scope.

#### Scenario: Initial summary is slow
- **WHEN** an authorized user opens a global Sales workspace and summary calculation is slow
- **THEN** the initial page contains the table and visible summary placeholders without executing the expensive summary calculation
- **AND** summaries load automatically after initialization and replace placeholders when complete
- **AND** placeholders SHALL NOT present an unknown balance as zero

#### Scenario: Summary load fails
- **WHEN** a summary request fails
- **THEN** summary loading ends with a visible retry action
- **AND** the table remains usable
- **AND** retry uses current applied inputs and the immutable customer constraint

### Requirement: Global Sales refresh feedback follows the operation
The global Sales workspace SHALL show distinct summary and table pending states for their respective refresh work, including the complete card-selection-to-table-update sequence. Completion or failure SHALL release the relevant pending state without clearing unrelated in-flight work.

#### Scenario: Card selection updates the table
- **WHEN** a user selects or deselects a summary card
- **THEN** table feedback remains visible until the resulting table update completes or fails
- **AND** card highlighting and shareable URL selection match the resulting filter
- **AND** unchanged summary totals are not recalculated solely for selection

#### Scenario: Applied summary inputs change
- **WHEN** business or date scope changes through Apply Filter
- **THEN** both regions indicate their own pending work until each operation completes
- **AND** displayed results settle on the latest applied scope even if another filter is applied while a summary is pending

#### Scenario: Unchanged inputs and explicit reset
- **WHEN** Apply Filter is submitted with unchanged summary inputs
- **THEN** unchanged summaries are not recalculated
- **WHEN** Reset is explicitly selected, including from default state
- **THEN** summaries are recalculated from current settlement data

### Requirement: Global Sales summary reads scale without per-sale balance queries
The system SHALL calculate global Sales summary counts and monetary totals without issuing a separate balance query for each matching sale. Results SHALL retain canonical active-settlement effects, per-sale rounding and clamping, archive and eligibility exclusions, customer isolation, and existing summary date scope.

#### Scenario: Settlement components are combined once
- **WHEN** eligible sales contain multiple active or invalidated payments, credits, owner transfers, and committed return-debt reductions
- **THEN** outstanding and overdue counts and totals equal authoritative per-sale live balances
- **AND** joining multiple settlement records does not multiply their amounts
- **AND** increasing the matching-sale count does not introduce per-sale balance-query growth

#### Scenario: Recent collections retain their meaning
- **WHEN** recent collection totals are calculated
- **THEN** existing active-payment amount, distinct-sale count, fully-paid eligibility, and recent-period boundaries are retained
- **AND** loading and aggregation changes do not broaden summary scope

#### Scenario: Table return reductions use fresh read aggregates
- **WHEN** global Sales rows display live balances with committed return-debt reductions
- **THEN** each reduction is counted once without a separate reduction query per displayed row
- **AND** subsequent payment reconciliation revalidates current financial state rather than trusting previously loaded aggregates
