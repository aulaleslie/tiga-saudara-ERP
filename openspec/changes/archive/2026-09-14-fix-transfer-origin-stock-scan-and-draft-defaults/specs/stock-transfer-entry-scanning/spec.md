## ADDED Requirements

### Requirement: Authorized origin inventory defines transfer-entry product eligibility
After authorizing that the selected origin belongs to the active tenant, the system SHALL determine transfer-entry product eligibility from authoritative stock and serial state at that exact origin and condition. It SHALL NOT reject an otherwise eligible product solely because the product catalogue `setting_id` differs from the origin business, and SHALL NOT expose or accept products whose only inventory exists outside the selected origin.

#### Scenario: Scan cross-catalogue product stocked at origin
- **WHEN** an authorized origin contains eligible stock for an active stock-managed product whose catalogue owner is another business and the operator scans its exact barcode
- **THEN** the resolver returns that product as an eligible candidate using only stock at the selected origin

#### Scenario: Scan cross-catalogue conversion barcode
- **WHEN** an eligible origin-stocked product belongs to another catalogue and its exact conversion barcode represents a valid positive whole base-unit factor
- **THEN** the resolver returns the conversion candidate and applies the authoritative factor under the existing quantity rules

#### Scenario: Search cross-catalogue product stocked at origin
- **WHEN** an operator deliberately searches for an active stock-managed product that has eligible stock at the selected origin but a different catalogue owner
- **THEN** the product may appear without exposing protected stock quantity, bucket, tax, or location provenance

#### Scenario: Resolve cross-catalogue serial at origin
- **WHEN** an exact live serial belongs to an active stock-managed product from another catalogue but is available at the selected origin under the selected condition
- **THEN** the resolver returns the serial candidate subject to all existing reservation, dispatch, return, custody, and availability guards

#### Scenario: Reject product stocked only elsewhere
- **WHEN** a product belongs to any catalogue but has no eligible stock or serial at the selected authorized origin
- **THEN** the system does not return or apply it through scan, conversion, search, or ambiguity selection

#### Scenario: Reject unauthorized origin
- **WHEN** an operator selects or crafts an origin outside the active tenant
- **THEN** the resolver rejects the request before discovering product identity or inventory

#### Scenario: Preserve condition isolation
- **WHEN** the selected origin contains stock for a cross-catalogue product only in the condition opposite the transfer mode
- **THEN** the product remains ineligible for that transfer entry
