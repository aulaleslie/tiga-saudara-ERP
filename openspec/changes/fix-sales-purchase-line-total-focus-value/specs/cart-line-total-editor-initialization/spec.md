## ADDED Requirements

### Requirement: Total Baris editor initializes from the authoritative line total
The system SHALL populate a standard cart row's editable `Total Baris` field with the full current authoritative line total when the user opens the editor. The opened raw numeric value SHALL represent the same amount as the collapsed formatted line total, including every significant digit.

#### Scenario: Trailing-zero line total opens without truncation
- **WHEN** a standard cart row has an authoritative final line total of `46500`
- **AND** the user opens the `Total Baris` editor
- **THEN** the editor SHALL show `46500` as its raw numeric value
- **AND** it SHALL NOT show `4650` or another stale/truncated value

#### Scenario: User-entered replacement remains editable
- **WHEN** a user replaces the initialized Total Baris value with `50000`
- **AND** commits the edit through the normal cart interaction
- **THEN** the cart SHALL receive `50000` as the requested final line total
- **AND** the normal line-total calculation and validation rules SHALL apply
