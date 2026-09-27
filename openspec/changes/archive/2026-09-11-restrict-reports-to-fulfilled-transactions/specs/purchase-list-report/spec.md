## MODIFIED Requirements

### Requirement: Document status multi-select filter
The system SHALL provide a multi-select `Status Dokumen` filter containing only report-eligible purchase lifecycle statuses with Bahasa Indonesia labels: `RECEIVED` (`Diterima`) and `RETURNED PARTIALLY` (`Diretur Sebagian`). The default report population SHALL use the same eligibility set.

#### Scenario: Document status options are restricted to eligible statuses
- **WHEN** a user opens the `Status Dokumen` filter
- **THEN** the available options include `Diterima` and `Diretur Sebagian`
- **AND** drafted, approval, rejected, partially received, and fully returned options are unavailable

#### Scenario: Document status filter applies OR matching
- **WHEN** a user selects multiple eligible document statuses and clicks `Filter`
- **THEN** the report includes purchase detail rows whose purchase document status matches any selected status
- **AND** the report excludes rows that do not meet purchase report eligibility
