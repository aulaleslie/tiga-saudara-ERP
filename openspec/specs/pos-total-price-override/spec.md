# pos-total-price-override Specification

## Purpose
TBD - created by archiving change pos-total-price-override. Update Purpose after archive.
## Requirements
<!-- Retired capability; historical cart-total override behavior is preserved in archived change artifacts. -->
### Requirement: Historical cart-total override records SHALL remain readable
The system SHALL continue to deserialize and display historical `TOTAL_PRICE_OVERRIDE` records without treating them as actionable cart state or authorizing new cart-wide mutations.

#### Scenario: Historical record is read-only
- **WHEN** approval history or audit history contains a `TOTAL_PRICE_OVERRIDE` record
- **THEN** the record MUST render without error
- **AND** it MUST be presented as read-only historical data
- **AND** it MUST NOT authorize a new cart mutation

