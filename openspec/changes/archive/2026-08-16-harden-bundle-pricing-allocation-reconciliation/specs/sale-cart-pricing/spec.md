## MODIFIED Requirements

### Requirement: Sales bundled rows SHALL bypass automatic product repricing
When a Sales cart row has a selected bundle, the system SHALL preserve its current editable parent row price during customer, quantity, tax, discount, and cart reconciliation flows instead of replacing it with product or bundle definition prices.

#### Scenario: Quantity change preserves overridden bundled row unit price
- **WHEN** a Sales bundled row has a user-configured parent row price
- **AND** the user changes the row quantity
- **THEN** the row unit price SHALL remain unchanged
- **AND** the row subtotal SHALL recalculate from that price and the new quantity
- **AND** cascading quantity pricing SHALL NOT replace the bundled row price

#### Scenario: Tax and discount recalculation preserves bundled row unit price
- **WHEN** a Sales bundled row has a user-configured parent row price
- **AND** tax inclusion, line tax, row discount, or global discount changes
- **THEN** recalculation SHALL preserve that parent row unit price
- **AND** the resulting totals SHALL be derived from the preserved price

#### Scenario: Component snapshots remain fixed after parent price override
- **WHEN** a user changes the bundled parent row sale price
- **THEN** the bundle component informational-price snapshots SHALL remain unchanged
- **AND** no component price SHALL be proportionally repriced from the parent override

### Requirement: Sales bundle component prices SHALL be informational only
When Sales creates, updates, hydrates, discounts, or persists a sale with selected bundle components, component informational prices SHALL NOT contribute to cart row subtotals, `sale_details` subtotals, sale header totals, payment due totals, or discount target counts.

#### Scenario: Component informational prices do not add to totals
- **WHEN** a selected bundle contains component informational-price snapshots
- **THEN** Sales SHALL NOT add those values to the parent cart row subtotal
- **AND** Sales SHALL NOT add those values to the Sale total

#### Scenario: Component prices remain read-only in Sales cart
- **WHEN** a Sales cart row contains selected bundle components
- **THEN** component prices SHALL be hidden or displayed read-only
- **AND** the user SHALL NOT be able to edit component prices from the Sales cart

#### Scenario: Persisted components remain non-billable
- **WHEN** Sales creates or updates `sale_bundle_items` for a selected bundle
- **THEN** the component rows SHALL persist zero non-billable commercial price and subtotal values
- **AND** the parent `sale_details` row SHALL remain the complete commercial representation

#### Scenario: Parent price override does not make components billable
- **WHEN** a user overrides the bundled parent row price
- **THEN** the overridden price SHALL remain entirely represented by the parent Sale row
- **AND** component rows SHALL remain non-billable

## ADDED Requirements

### Requirement: Sales discounts SHALL target commercial parent rows
Normal Sales row and global discounts SHALL operate on commercial Sale rows only and SHALL NOT treat bundle component rows as separate discount targets.

#### Scenario: Bundle row discount reduces only the parent row
- **WHEN** a user applies a row discount to a bundled Sale row
- **THEN** the discount SHALL reduce only that parent row's commercial amount
- **AND** component informational prices and non-billable component rows SHALL remain unchanged

#### Scenario: Global discount is prorated across commercial Sale rows
- **WHEN** a Sale contains multiple commercial item rows and a global discount
- **THEN** the global discount SHALL be prorated across those commercial rows using the established Sales transaction rounding convention
- **AND** bundle component rows SHALL NOT increase the number of proration targets

#### Scenario: Bundled row global share reduces only its parent
- **WHEN** a bundled commercial row receives a share of the global discount
- **THEN** that share SHALL reduce only the bundle parent row
- **AND** its component informational prices SHALL remain unchanged
