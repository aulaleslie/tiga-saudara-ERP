## ADDED Requirements

### Requirement: Bundled Product Replacement Preview SHALL Use Source Sale Detail Commercial Amount
When approval preview evaluates a POS bundled product replacement line, the system SHALL value the replacement from the source sale detail commercial amount for the returned quantity. The preview MUST NOT use the original POS bundle list price when the source sale detail has an owner-specific parent residual amount. The preview amount, original sale correction amount, generated replacement sale effects, and persisted execution plan values MUST use the same commercial amount for same-owner and cross-owner replacement paths.

#### Scenario: Cross-owner bundled replacement preview uses replaced sale detail amount
- **WHEN** an authorized user opens approval preview for a pending POS Return replacing a bundled parent item from a source sale detail with `price` 6,085,000
- **AND** the POS return snapshot line total is 6,100,000 from the original bundle list price
- **AND** the selected replacement serial belongs to a different owner
- **THEN** the preview shows original sale correction amount 6,085,000
- **AND** the preview's generated replacement-owner Sale effects show payment amount 6,085,000
- **AND** the preview does not use 6,100,000 as the replacement valuation.

#### Scenario: Same-owner bundled replacement preview uses replaced sale detail amount
- **WHEN** an authorized user opens approval preview for a pending POS Return replacing a bundled parent item from the same owner
- **AND** the source sale detail commercial amount differs from the POS bundle list price
- **THEN** the preview values replacement audit and execution context from the source sale detail commercial amount
- **AND** the preview does not value the replacement from the POS bundle list price.
