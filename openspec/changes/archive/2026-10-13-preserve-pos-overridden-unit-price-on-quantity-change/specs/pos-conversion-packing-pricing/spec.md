# Spec Delta

## MODIFIED Requirements

### Requirement: Reprice on quantity and customer-tier change

The system SHALL re-pack and re-price an automatically priced line on every quantity change and on every customer-tier change, recomputing the line total from the packing rules. Line price SHALL NOT remain frozen at add time. A successful manual unit-price override, including the unit price carried forward from a prior row-total override, SHALL take precedence over automatic packing and tier pricing on later quantity changes. A customer change SHALL continue to clear applied overrides and reprice under the selected customer's rules.

#### Scenario: Quantity change updates the price
- **WHEN** an automatically priced packed line has quantity 5 priced at 210000 and the cashier changes quantity to 6 (non-tier, base price 45000)
- **THEN** the line total is recomputed to 255000

#### Scenario: Selecting a reseller customer reprices packed lines
- **WHEN** a packed line of quantity 6 is priced at 255000 for a non-tier customer and a reseller customer (base-unit price 42000) is then selected
- **THEN** the line total is recomputed to 252000

#### Scenario: Overridden packed row keeps its unit price on quantity change
- **WHEN** a packed row has an authoritative overridden unit price and its quantity changes
- **THEN** its unit price MUST remain the overridden unit price
- **AND** its new total MUST be calculated from the new quantity without automatic conversion or tier repricing

#### Scenario: Customer change clears packed row override
- **WHEN** a customer change follows an override on a packed row
- **THEN** the override MUST be cleared
- **AND** the row MUST be repriced for the new customer using the applicable automatic rules

### Requirement: Cached pricing basis with zero-DB re-pricing

The system SHALL capture all pricing inputs required for packing (conversion factor, box price, base-unit price, tier prices, and tax identity/rate) onto the cart line once, at add or scan time, as a `pricing_basis`. Subsequent quantity changes on automatically priced rows and customer-tier changes SHALL re-pack using the cached `pricing_basis` without issuing additional database queries. Prices SHALL be frozen at scan time; the system SHALL NOT re-query pricing mid-cart. Quantity changes on overridden rows SHALL retain the overridden unit price and SHALL NOT re-pack.

#### Scenario: Quantity update issues no pricing queries
- **WHEN** an automatically priced packed line's quantity is changed
- **THEN** the line is re-packed using the cached `pricing_basis`
- **AND** no database query is issued to resolve the conversion, product, or tax price

#### Scenario: Admin price change mid-cart does not affect an existing line
- **WHEN** a line is added with a cached box price of 210000 and an administrator later changes the box price to 220000
- **THEN** subsequent quantity changes on that automatically priced line re-pack against the cached 210000

#### Scenario: Overridden quantity update does not use the cached price
- **WHEN** a packed line has an applied manual price override and its quantity changes
- **THEN** the overridden unit price MUST remain authoritative
- **AND** the cached box and tier prices MUST NOT replace it
