# Spec Delta

## ADDED Requirements

### Requirement: Generated Sale details retain originating POS line identity
For every newly finalized POS checkout, each generated Sale detail SHALL retain an unambiguous association with its originating customer-facing POS transaction line. The association SHALL cover inline and owner-split posting, including parent-residual and component-only bundle owner groups, and SHALL survive later monetary edits without changing checkout snapshots or bundle-item allocation snapshots.

#### Scenario: Three-owner bundle
- **WHEN** one POS bundle line posts its parent residual and two component allocations to three owner Sales
- **THEN** all three authoritative Sale details identify the same originating POS transaction line
- **AND** each Sale detail remains attached to its own Sale and owner.

#### Scenario: Two customer-facing lines share an owner Sale
- **WHEN** two POS lines both contribute to one generated owner Sale
- **THEN** that Sale's details retain distinct originating POS line associations, including when the lines use the same product or bundle definition.

#### Scenario: Monetary edit preserves lineage
- **WHEN** an authorized monetary edit changes an eligible generated Sale detail
- **THEN** its originating POS line association remains unchanged.
