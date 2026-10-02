# Spec Delta

## ADDED Requirements

### Requirement: Initial ordinary line price reflects selected customer tier
When a product is added as an ordinary line (not a bundle and not a packed/box-priced product) to a cart that already has a customer selected, the system SHALL set the line's initial unit price from that customer's tier: the tier 1 price for WHOLESALER and the tier 2 price for RESELLER. If the applicable tier price is zero or unset, or the customer has no tier, the system SHALL use the base sale price, retaining the existing fallback to the product's own price when no per-setting price exists. A line priced by a tier price SHALL be identified as tier-priced. Bundle lines and packed-line pricing SHALL be unaffected by this requirement.

#### Scenario: Wholesaler selected before adding product
- **WHEN** a WHOLESALER customer is selected on an empty cart and an ordinary product with a tier 1 price is added
- **THEN** the new line's unit price equals the product's tier 1 price and the line is identified as tier-priced

#### Scenario: Reseller selected before adding product
- **WHEN** a RESELLER customer is selected on an empty cart and an ordinary product with a tier 2 price is added
- **THEN** the new line's unit price equals the product's tier 2 price

#### Scenario: Tier price not configured
- **WHEN** a tier customer is selected and the product's applicable tier price is zero or unset
- **THEN** the new line's unit price equals the base sale price

#### Scenario: Customer without tier
- **WHEN** a customer without a tier is selected and an ordinary product is added
- **THEN** the new line's unit price equals the base sale price

#### Scenario: Conversion unit for tier customer
- **WHEN** a tier customer is selected and an ordinary product is added using a conversion unit
- **THEN** the line's unit price is the tier price per base unit and no conversion pricing is applied

#### Scenario: Repeated add merges
- **WHEN** the same ordinary product is added twice with the same tier customer selected
- **THEN** both additions coalesce into one line at the tier unit price

#### Scenario: Bundle unaffected
- **WHEN** a tier customer is selected and a bundle is added
- **THEN** the bundle line's unit price is the bundle sale price, unchanged by tier
