## ADDED Requirements

### Requirement: Product resolution via shared canonical identity
Product matching for this workflow SHALL use the shared canonical catalog identity, using the same normalization and canonical-key system as other import paths to ensure consistent product lookup across all importers.

#### Scenario: Product resolves consistently via canonical identity
- **WHEN** a workbook row's product name matches via canonical identity to an existing product
- **THEN** the system SHALL use the same resolution method as purchase and sales imports

