# Spec Delta

## Purpose

Ensure product create/edit conversion tables remove the intended row and preserve coherent surviving-row data through submission and validation redirects.

## ADDED Requirements

### Requirement: Targeted conversion row removal

The product create/edit editor SHALL remove exactly the conversion row selected by its Hapus action, for saved conversions, newly added conversions, and conversions restored after server validation failure. Remaining rows SHALL retain their selected units, factors, barcodes, canonical prices, and sale/purchase enablement values.

#### Scenario: Remove an existing conversion
- **WHEN** a user opens product edit with saved conversions and removes a first, middle, or last row
- **THEN** only the targeted row SHALL disappear
- **AND** surviving rows SHALL retain their current values

#### Scenario: Remove a row after validation failure
- **WHEN** a create or edit submission returns with validation errors and the user removes a restored conversion row
- **THEN** the selected row SHALL disappear and surviving rows SHALL remain editable with their restored values

#### Scenario: Repeated removal and addition
- **WHEN** a user removes a conversion, adds another, and removes another surviving row
- **THEN** each action SHALL affect only its selected row without duplicating or losing surviving values

### Requirement: Coherent conversion submission after removal

The product create/edit editor SHALL submit each surviving conversion's unit, factor, barcode, canonical price, ID when present, and sale/purchase enablement under the same row index. The removed row MUST NOT be submitted or recreated by a validation round trip.

#### Scenario: Surviving dropdown follows its row
- **WHEN** rows A, B, and C have distinct units and values, the user changes C's unit, removes B, and submits
- **THEN** A and C SHALL each submit a coherent conversion entry with their current selected units and other values
- **AND** B SHALL be absent from the submitted conversions

#### Scenario: Another validation failure after removal
- **WHEN** the user removes a first or middle row and submits with an unrelated server validation error
- **THEN** only surviving conversions SHALL be restored, each with its own unit and values
- **AND** the removed conversion SHALL remain absent
