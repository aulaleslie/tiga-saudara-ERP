# pos-line-total-override Specification

## ADDED Requirements

### Requirement: Customer selection change SHALL invalidate row-total overrides
When the selected customer on a mutable POS cart changes (including selecting a customer, changing customer tier, or clearing to guest), any applied row-total override MUST be invalidated and removed. The line MUST revert to standard resolved pricing for the newly selected customer or base pricing for guests.

#### Scenario: Row-total override is invalidated by customer selection change
- **WHEN** a cart containing an applied row-total override has its selected customer changed
- **THEN** the row-total override MUST be invalidated and removed
- **AND** the line MUST revert to resolved standard pricing for the newly selected customer
- **AND** all canonical override metadata MUST be cleared
