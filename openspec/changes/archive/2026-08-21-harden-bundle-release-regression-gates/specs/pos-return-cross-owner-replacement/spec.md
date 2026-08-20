## ADDED Requirements

### Requirement: Replacement-owner Sale preserves original calendar date
The generated replacement-owner Sale and its payment SHALL preserve the original Sale's persisted calendar date while retaining their own creation and execution timestamps.

#### Scenario: Cross-owner replacement is approved on a later day
- **WHEN** final approval creates a replacement-owner Sale after the original Sale date
- **THEN** the replacement-owner Sale date and payment date SHALL equal the original Sale calendar date
- **AND** date verification SHALL compare normalized persisted values rather than object identity
