# Spec Delta

## MODIFIED Requirements

### Requirement: Average purchase price remains purchase-derived
The system SHALL reserve automatic average purchase price calculation for purchase processing and approved purchase-history normalization. Ordinary product create, standard product edit, and general cross-business price editing SHALL NOT recalculate or overwrite average purchase price. An authorized user MAY deliberately replace the global average purchase price only through the dedicated guarded global HPP workflow.

#### Scenario: Manual pricing follows prior purchase processing
- **WHEN** a product price row already has an average purchase price calculated by purchase processing
- **AND** a user changes the product's purchase price through standard editing or cross-business price management
- **THEN** the system SHALL retain the calculated average purchase price unchanged

#### Scenario: Dedicated global HPP workflow changes average price
- **WHEN** an authorized user successfully completes the dedicated global HPP workflow
- **THEN** the submitted average purchase price SHALL become the value for every business

#### Scenario: Purchase processing follows the manual correction
- **WHEN** a later approved purchase receipt recalculates average purchase price
- **THEN** the purchase workflow SHALL remain authoritative and MAY replace the manually corrected global value
