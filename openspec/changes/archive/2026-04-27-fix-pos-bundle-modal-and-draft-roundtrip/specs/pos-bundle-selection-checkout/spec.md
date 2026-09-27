## ADDED Requirements

### Requirement: Bundle selection modal SHALL display the authoritative bundle sale price

The bundle selection modal in the POS sell view SHALL display the bundle's final sale price (`bundle_sale_price`) as the price shown to the cashier for each available bundle option. The legacy bundle add-on price SHALL remain available in the modal payload as a separate field for compatibility but SHALL NOT be used as the displayed price.

#### Scenario: Modal shows the bundle sale price for each option
- **WHEN** the cashier opens the bundle selection modal for a bundle-parent product
- **THEN** each rendered bundle option MUST show the bundle's `bundle_sale_price` as the headline price
- **AND** the displayed price MUST equal the unit price the cart line will charge for that bundle

#### Scenario: Modal payload preserves the legacy bundle add-on price
- **WHEN** the bundle selection endpoint returns available bundles for a parent product
- **THEN** each bundle entry MUST include the legacy add-on price in a `legacy_price` field alongside the authoritative `price` (`bundle_sale_price`)
- **AND** the frontend MUST render the authoritative `price` and MUST NOT render `legacy_price` as the cashier-facing figure

#### Scenario: Bundle without a configured sale price falls back to zero
- **WHEN** a bundle has no `bundle_sale_price` configured
- **THEN** the modal payload MUST return `0` as `price`
- **AND** the cashier SHALL still see the bundle option with the zero price visible
