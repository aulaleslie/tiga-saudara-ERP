## MODIFIED Requirements

### Requirement: Pending proposals persist without inventory mutations
Create/update SHALL atomically persist a versioned count proposal with location, location-setting context, per-product counts, serial text/condition/source references, and baseline. A newly created or edited proposal SHALL have `draft` status, SHALL remain editable by authorized counters, and SHALL NOT notify approvers until explicit submission. Actual stock, serial records, transactions, and stock histories SHALL remain unchanged. Explicit zero rows SHALL remain part of the proposal and omitted products SHALL remain outside it. Historical legacy-format adaptation is not required for the supported workflow.

#### Scenario: Save and reopen counts
- **WHEN** a draft proposal containing good/bad counts and unregistered serial text is saved and reopened
- **THEN** all counts, serial assignments, and baseline information are restored exactly without live inventory changes or an approval-needed notification

#### Scenario: Edit location or encounter validation errors
- **WHEN** a valid location change is saved
- **THEN** the adjustment header and proposal use the same new location
- **AND** failed validation preserves the attempted location, counts, serials, date, and note for correction

#### Scenario: Explicit zero and omitted product
- **WHEN** a proposal includes one product at zero and omits another
- **THEN** the zero-count product remains explicitly proposed as zero and no count is inferred for the omitted product

#### Scenario: Save a rejected proposal revision
- **WHEN** an authorized counter edits and saves a rejected proposal
- **THEN** the revised document becomes `draft` and requires explicit resubmission before approval

## REMOVED Requirements

### Requirement: Legacy approval cannot apply new count proposals
**Reason**: The redesigned Stock Opname becomes the supported production workflow and gains its own compatible submit, review, and approval behavior; preserving historical legacy-format approval is outside scope.

**Migration**: Convert existing versioned normal `pending` documents to `draft`, route redesigned documents exclusively through the new lifecycle, and do not expose the older approval implementation for them.
