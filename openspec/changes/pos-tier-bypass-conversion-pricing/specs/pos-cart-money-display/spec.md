## ADDED Requirements

### Requirement: Active POS monetary totals display two decimal places

The active POS sell flow SHALL display cart and checkout-facing monetary totals using the configured currency symbol and separators with exactly two decimal places. Presentation formatting SHALL NOT change, round, or replace the underlying two-decimal snapshot amount used for checkout.

#### Scenario: Fractional Rupiah cart total remains visible
- **WHEN** the cart snapshot grand total is `78999.96` under Indonesian Rupiah formatting
- **THEN** the cart grand total displays `Rp79.000,00` or `Rp. 79.000,00` according to the configured symbol
- **AND** it MUST NOT display the rounded value `Rp79.000` without decimals

#### Scenario: Whole Rupiah cart total uses the same precision
- **WHEN** the cart snapshot grand total is `85000.00`
- **THEN** the cart grand total displays `Rp85.000,00` or `Rp. 85.000,00` according to the configured symbol

#### Scenario: Cart and checkout summary agree
- **WHEN** a cashier proceeds from a cart whose grand total is `78999.96` to the checkout stage
- **THEN** the cart total, payment summary total, and checkout receipt total each represent `78999.96`
- **AND** no stage presents a whole-Rupiah rounded substitute for that amount

#### Scenario: Formatting does not mutate checkout arithmetic
- **WHEN** a two-decimal total is rendered in the active POS sell flow
- **THEN** payment validation and checkout submission continue to use the original numeric snapshot total
- **AND** the locale-formatted display string is not submitted as the monetary value
