## ADDED Requirements

### Requirement: Manual product purchase price updates only the last purchase price
The product create and standard product edit workflows SHALL treat a manually entered purchase price as the setting's last purchase price. They SHALL NOT recalculate or overwrite average purchase price.

#### Scenario: Product creation with a purchase price
- **WHEN** a user creates a purchasable product and enters a purchase price
- **THEN** the system SHALL initialize each created per-business price row's last purchase price from that input
- **AND** the system SHALL initialize each row's average purchase price to zero

#### Scenario: Standard product edit changes purchase price
- **WHEN** a user edits a product's purchase price in the standard product edit workflow
- **THEN** the system SHALL update only the active business's last purchase price
- **AND** the system SHALL preserve that row's average purchase price

### Requirement: Average purchase price remains purchase-derived
The system SHALL reserve average purchase price calculation for purchase processing and approved purchase-history normalization, not manual product price maintenance.

#### Scenario: Manual pricing follows prior purchase processing
- **WHEN** a product price row already has an average purchase price calculated by purchase processing
- **AND** a user changes the product's purchase price through standard editing or cross-business price management
- **THEN** the system SHALL retain the calculated average purchase price unchanged
