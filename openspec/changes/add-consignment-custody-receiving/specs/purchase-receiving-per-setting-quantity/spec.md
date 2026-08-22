## ADDED Requirements

### Requirement: Ordinary Purchase receiving excludes consignment locations
The system SHALL prevent ordinary Purchase Receiving Notes from being created or approved for a location classified as consignment while preserving all existing ordinary receiving behavior for standard locations.

#### Scenario: Ordinary receiving selects locations
- **WHEN** an authorized user opens ordinary Purchase receiving
- **THEN** the location selector SHALL exclude locations where `is_consignment = true`

#### Scenario: Forged consignment location is submitted
- **WHEN** a request submits a consignment location for ordinary Purchase receiving
- **THEN** the system SHALL reject the request without creating a Received Note or changing stock

#### Scenario: Location is reclassified before approval
- **WHEN** a pending ordinary Received Note targets a location that becomes consignment-classified before approval
- **THEN** approval SHALL revalidate and reject the receiving without stock mutation

#### Scenario: Standard location behavior is preserved
- **WHEN** an ordinary Purchase receiving targets an eligible standard location
- **THEN** its existing lifecycle, per-setting quantities, stock, serial, cost, notification, and Purchase completion behavior SHALL remain unchanged
