# Spec Delta

## MODIFIED Requirements

### Requirement: Future product and bundle changes produce immutable feed events
The system SHALL record an immutable update-feed event only for qualifying changes completed after this capability is deployed. Qualifying changes SHALL be product creation, a change to `last_purchase_price`, a deliberate change to `average_purchase_price` through the dedicated global HPP workflow, a change to `sale_price`, `tier_1_price`, or `tier_2_price`, bundle creation, and a change to `bundle_sale_price`. Each event SHALL preserve its type, occurrence time, source or actor, affected setting, subject identifiers and display snapshots, and the authorized before and after price values needed to explain the change.

#### Scenario: Product is created after deployment
- **WHEN** a product is successfully created after deployment
- **THEN** the system records a product-created event containing the affected business price snapshot

#### Scenario: Tracked product price changes
- **WHEN** a persisted tracked price changes from one value to a different value
- **THEN** the system records the before and after values in a product-price-updated event

#### Scenario: Global HPP correction affects multiple businesses
- **WHEN** the dedicated global HPP workflow changes average purchase price for multiple businesses
- **THEN** the system records the changed per-business before and after average values under one operation group
- **AND** average purchase price details SHALL follow the existing purchase-price field visibility rules

#### Scenario: Price write is a no-op
- **WHEN** a workflow persists the same tracked price values already stored
- **THEN** the system does not record a product-price-updated event

#### Scenario: Bundle is created
- **WHEN** a bundle is successfully created after deployment
- **THEN** the system records a bundle-created event with its business and bundle sale price

#### Scenario: Bundle price changes
- **WHEN** a bundle sale price changes from one persisted value to another
- **THEN** the system records a bundle-price-updated event with its before and after values

#### Scenario: Existing historical catalog data
- **WHEN** the capability is deployed over existing products, prices, and bundles
- **THEN** the system does not backfill events for those existing records

