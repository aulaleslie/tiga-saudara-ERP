## MODIFIED Requirements

### Requirement: Business changes rehydrate taxation without repricing
When an authorized user changes the selected business before saving, the system SHALL preserve products, quantities, manually entered unit prices, discounts, shipping, and other non-tax cart values. It SHALL rehydrate tax context for the target business and recompute tax-derived amounts through the existing document normalization behavior. For a Sales cart only, it SHALL additionally reprice each automatic non-bundled line using the target business's `product_prices` row and the active customer's tier; it SHALL not reprice manually priced or bundled Sales lines. Purchase carts SHALL continue to rehydrate taxation without repricing.

#### Scenario: Business changes from PKP to non-PKP
- **WHEN** an authorized user changes a populated cart from a PKP business to a non-PKP business
- **THEN** the system SHALL remove tax assignments and tax-derived values without changing non-tax cart values
- **AND** for a Sales cart, each automatic non-bundled line SHALL be repriced from the target business before its non-PKP amounts are recalculated
- **AND** manually priced and bundled Sales lines SHALL retain their unit prices

#### Scenario: Business changes from non-PKP to PKP
- **WHEN** an authorized user changes a populated cart from a non-PKP business to a PKP business
- **THEN** the system SHALL preserve non-tax cart values, load target-business tax options, and require valid target-business tax selections before save
- **AND** for a Sales cart, each automatic non-bundled line SHALL be repriced from the target business before its PKP amounts are recalculated
- **AND** manually priced and bundled Sales lines SHALL retain their unit prices
