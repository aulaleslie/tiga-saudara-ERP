## ADDED Requirements

### Requirement: Canonical import product identity
The system SHALL derive a global canonical product identity for import product names by replacing supported Unicode spacing characters, trimming, collapsing whitespace, removing documented import-only owner markers in the source context, and case-folding the resulting clean name. The system SHALL store the clean, case-preserving name for a newly created import product and SHALL use the canonical identity for matching.

#### Scenario: Formatting variants resolve to one product
- **WHEN** import rows name a product with differences only in letter case, surrounding whitespace, repeated/Unicode whitespace, a leading import asterisk, or a trailing import `TP` marker
- **THEN** the system SHALL derive the same canonical identity for the rows
- **AND** the system SHALL resolve them to one global catalog product

#### Scenario: Non-marker product text is preserved
- **WHEN** a product name contains punctuation or text that is not a documented import-only owner marker
- **THEN** the system SHALL preserve that text in the clean display name and canonical identity
- **AND** the system SHALL NOT use fuzzy or punctuation-stripping matching

### Requirement: Atomic import product resolution and creation
Every import path that creates catalog products SHALL use the shared canonical identity resolver. The resolver SHALL reuse an existing uniquely identified product or atomically create exactly one new product with the canonical identity; it SHALL not create a second product for an existing canonical identity.

#### Scenario: Parallel imports discover the same new product
- **WHEN** two or more import jobs concurrently resolve an absent canonical product identity
- **THEN** exactly one catalog product SHALL be created for that identity
- **AND** every successful job SHALL use that product after the canonical identity is reserved

#### Scenario: Existing product is reused despite source-code difference
- **WHEN** an import row has a canonical product identity that resolves to an existing product and supplies a different or unavailable product code
- **THEN** the system SHALL use the existing product
- **AND** the system SHALL NOT create another product solely because of the code difference

### Requirement: Canonical identity uniqueness and legacy conflict safety
The system SHALL persist a unique canonical product identity for every product created by an import. Existing products with a canonical identity collision SHALL be reported as an actionable conflict until explicitly reconciled; import processing SHALL not select one arbitrarily or create a further duplicate.

#### Scenario: Existing duplicate identity blocks non-creating resolution
- **WHEN** a non-creating import resolves a canonical identity represented by more than one unreconciled catalog product
- **THEN** the system SHALL mark the row as an actionable ambiguous-identity error or skip
- **AND** the system SHALL NOT mutate prices, stock, documents, or catalog products for that row

#### Scenario: Existing duplicate identity is encountered by a creating import
- **WHEN** a creating import resolves a canonical identity represented by more than one unreconciled catalog product
- **THEN** the system SHALL mark the source row or owning document group as an actionable conflict according to its existing failure semantics
- **AND** the system SHALL NOT create another product for that identity

### Requirement: Duplicate catalog identity preflight and reconciliation
The system SHALL provide a read-only preflight that reports every canonical-identity collision with the candidate product IDs, names, codes, and supported reference counts. The system SHALL require explicit operator confirmation of a survivor before reconciling a collision group and SHALL preserve an audit record of the decision.

#### Scenario: Preflight finds duplicate products
- **WHEN** an operator runs the catalog identity preflight on products with the same canonical identity
- **THEN** the system SHALL report every candidate product in each collision group
- **AND** the system SHALL not alter products or references

#### Scenario: Operator reconciles a duplicate group
- **WHEN** an operator explicitly selects a valid survivor for a reported collision group
- **THEN** the system SHALL transactionally repoint supported references to the survivor, retire rather than silently delete redundant products, and record the reconciliation decision
- **AND** the survivor SHALL receive the group's canonical identity

