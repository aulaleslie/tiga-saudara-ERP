# Spec Delta

## MODIFIED Requirements

### Requirement: Location context governs counting
The editor SHALL use a searchable multi-location selector with persistent selected labels and SHALL offer every active standard location without restricting options to the user's current active setting. Counting SHALL require at least one selected location and SHALL reject inactive, nonexistent, duplicate, or consignment locations before resolving scans, capturing baselines, or exposing stock information. The server SHALL authorize the complete submitted location set and client-provided selected or pending identifiers MUST NOT bypass that path. A populated location-set change SHALL require confirmation before clearing location-dependent state.

#### Scenario: Select locations across active settings
- **WHEN** an authorized stock-opname user searches for and selects active standard locations belonging to different settings
- **THEN** the editor retains every selection and uses their combined stock as the counting context
- **AND** changing the user's active setting is not required

#### Scenario: Change location after counting
- **WHEN** the user confirms adding, removing, or replacing a selected location after rows have been populated
- **THEN** the editor clears products, counts, serials, errors, and baselines together and displays the new selected labels
- **AND** cancelling the change preserves the prior location set and counting state

#### Scenario: Location determines tax allocation
- **WHEN** good and damaged differences are allocated across selected locations
- **THEN** every affected quantity is classified according to the authoritative PKP status of the location where the effect is applied
- **AND** PKP locations remain after all eligible non-PKP locations in the allocation priority

#### Scenario: Reject tampered location state
- **WHEN** a client submits an inactive, nonexistent, duplicate, consignment, or otherwise unauthorized location identifier
- **THEN** the editor rejects the complete change before any baseline or stock query uses it
- **AND** it preserves or restores the last valid selected location set without disclosing stock from rejected locations

### Requirement: Condition entry preserves separate counts and review evidence
The editor SHALL provide a Good/Bad toggle directing subsequent inputs without changing prior entries. It SHALL display the combined existing and proposed good/bad counts and signed differences for the selected location pool, with baseline capture time, and SHALL retain per-location baseline evidence for review and deterministic allocation. Known source serial information and proposed condition SHALL remain reviewable.

#### Scenario: Switch counting condition
- **WHEN** a user switches from Good to Bad and scans an ordinary barcode
- **THEN** good count is preserved and bad count increases by one

#### Scenario: Compare proposed stock
- **WHEN** the selected locations have a combined baseline of good 10 and bad 1 and proposed counts are good 8 and bad 3
- **THEN** the comparison displays differences of minus 2 good and plus 2 bad
- **AND** save/edit retains the captured per-location baselines rather than presenting them as approval-time live stock

### Requirement: Pending proposals persist without inventory mutations
Create/update SHALL atomically persist a versioned count proposal with an ordered, unique set of selected locations, authoritative location-setting context, per-product counts, serial text/condition/source references, and per-location baselines. A newly created or edited proposal SHALL have `draft` status, SHALL remain editable by authorized counters, and SHALL NOT notify approvers until explicit submission. Actual stock, serial records, transactions, and stock histories SHALL remain unchanged. Explicit zero rows SHALL remain part of the proposal and omitted products SHALL remain outside it. Historical single-location versioned and legacy documents SHALL remain readable without being rewritten as multi-location documents.

#### Scenario: Save and reopen counts
- **WHEN** a draft proposal containing multiple locations, good/bad counts, and unregistered serial text is saved and reopened
- **THEN** the selected locations, counts, serial assignments, and per-location baseline information are restored exactly without live inventory changes or an approval-needed notification

#### Scenario: Edit location or encounter validation errors
- **WHEN** a valid location-set change is saved
- **THEN** the document relation and proposal contain the same authoritative selected location set
- **AND** failed validation preserves the attempted selections, counts, serials, date, and note for correction without partially changing the saved relation

#### Scenario: Explicit zero and omitted product
- **WHEN** a proposal includes one product at zero and omits another
- **THEN** the zero-count product remains explicitly proposed as zero and no count is inferred for the omitted product

#### Scenario: Save a rejected proposal revision
- **WHEN** an authorized counter edits and saves a rejected proposal
- **THEN** the revised document becomes `draft` and requires explicit resubmission before approval

#### Scenario: Read a historical single-location document
- **WHEN** a user opens a stock-opname document persisted before multi-location schema support
- **THEN** the system treats its historical location as a one-location pool for presentation and lifecycle behavior
- **AND** does not rewrite its saved draft or approval evidence merely because it was read
