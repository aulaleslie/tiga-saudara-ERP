## ADDED Requirements

### Requirement: POS dispatch details persist authoritative inventory classification
POS checkout posting SHALL explicitly persist `is_inventory_managed` on every generated DispatchDetail. A row that performs stock or serial mutation SHALL persist `true`, while an audit-only service or non-stock row SHALL persist `false`, including bundle parents and components in inline and split posting paths.

#### Scenario: Stock-managed bundle allocation is posted
- **WHEN** POS posting fulfills a bundle parent or component from an inventory allocation
- **THEN** its DispatchDetail SHALL persist `is_inventory_managed = true`
- **AND** the row's quantity, source location, product, bundle, tax, and serial evidence SHALL match the performed physical movement

#### Scenario: Non-stock bundle content is acknowledged
- **WHEN** POS posting creates an audit-only DispatchDetail for a service or non-stock bundle parent or component
- **THEN** its DispatchDetail SHALL persist `is_inventory_managed = false`
- **AND** no stock, serial, or inventory Transaction mutation SHALL occur for that row

#### Scenario: Split bundle posting produces multiple owner groups
- **WHEN** a bundle is fulfilled across multiple POS split groups
- **THEN** each generated DispatchDetail SHALL carry the classification of its own physical or audit-only posting path
- **AND** classification SHALL NOT be copied from an unrelated parent or sibling group
