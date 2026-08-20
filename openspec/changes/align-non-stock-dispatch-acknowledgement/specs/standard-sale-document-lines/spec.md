## MODIFIED Requirements

### Requirement: Dispatch aggregates preserved fulfillment document rows by fulfillment key
The system SHALL keep dispatch acknowledgement demand aggregation based on the Sale and product/tax/bundle fulfillment keys for stock-managed and non-stock parent products and bundle components, regardless of how many document rows exist. Inventory routing SHALL remain a separate per-detail decision and SHALL not change the fulfillment identity key.

#### Scenario: Dispatch view aggregates duplicate stock-managed sale details
- **WHEN** a standard Sale has multiple stock-managed `sale_details` rows with the same product, tax, and bundle state
- **AND** a user opens the dispatch page
- **THEN** the dispatch product table SHALL show aggregate dispatchable quantity for that product/tax/bundle
- **AND** the aggregate quantity SHALL equal the sum of the matching saved stock-managed sale detail quantities

#### Scenario: Dispatch validation uses aggregate remaining quantity
- **WHEN** a standard Sale has duplicate saved details for the same product, tax, bundle, and inventory-routing state
- **AND** a user submits a dispatch quantity for that product/tax/bundle
- **THEN** validation SHALL compare the submitted quantity against the aggregate remaining acknowledgement quantity
- **AND** validation SHALL NOT require a specific `sale_details` row to be selected

#### Scenario: Non-stock document rows create audit-only demand
- **WHEN** a standard Sale contains non-stock-managed detail rows or non-stock-managed bundle components
- **THEN** dispatch aggregation SHALL include their acknowledgement quantities
- **AND** their approved dispatch details SHALL affect fulfillment completion without creating inventory effects

#### Scenario: Standalone and bundled uses of one SKU remain separate
- **WHEN** the same product appears as a standalone Sale detail and as a bundle component
- **THEN** dispatch demand SHALL keep the standalone and bundled quantities separate by normalized `bundle_id`

#### Scenario: Same component in distinct bundles remains separate
- **WHEN** the same component product appears in two different bundle definitions on one Sale
- **THEN** dispatch demand SHALL keep those quantities separate by `bundle_id`

#### Scenario: Repeated equivalent bundle rows aggregate safely
- **WHEN** the same bundle definition is persisted as multiple transaction rows with the same product and tax context
- **THEN** dispatch demand SHALL aggregate equivalent component quantities under the same fulfillment key
- **AND** each approved physical quantity SHALL create inventory effects exactly once

#### Scenario: Tax contexts remain separate
- **WHEN** the same product and bundle context has different tax buckets on one Sale
- **THEN** dispatch demand and approved quantities SHALL remain separated by normalized `tax_id`

