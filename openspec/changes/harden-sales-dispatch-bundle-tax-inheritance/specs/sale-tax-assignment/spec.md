## ADDED Requirements

### Requirement: Dispatch tax bucket assignment preserves parent sale-line intent for bundle components
The system SHALL preserve sale-line tax intent during dispatch by assigning each bundle component to the tax bucket implied by its parent sale detail tax status.

#### Scenario: Bundled component under taxed sale detail
- **WHEN** dispatch processing evaluates a bundle component whose parent sale detail is taxed
- **THEN** stock checks and dispatch records for that component MUST use taxed bucket semantics.

#### Scenario: Bundled component under non-tax sale detail
- **WHEN** dispatch processing evaluates a bundle component whose parent sale detail is non-tax
- **THEN** stock checks and dispatch records for that component MUST use non-tax bucket semantics.
