## MODIFIED Requirements

### Requirement: Checkout split groups SHALL be derived by source and tax bucket
The system SHALL derive owner-specific groups from actual source setting and location while deriving bundled revenue from the POS owner's captured allocation snapshot. For bundled content, tax grouping SHALL make only the POS-owner allocation taxable when the POS owner is PKP; every other source-owner bundle allocation SHALL be non-tax.

#### Scenario: Parent and component revenue follow actual source owners
- **WHEN** a bundled parent and its components are fulfilled by different settings or locations
- **THEN** the parent residual SHALL be assigned to the parent source owner
- **AND** each fixed component allocation SHALL be assigned to its actual component source owner

#### Scenario: Component allocation retains POS-owner price
- **WHEN** a component allocation is assigned to a different source owner
- **THEN** its amount SHALL remain the value captured from the POS owner's bundle snapshot
- **AND** grouping SHALL NOT reprice it from the source owner's product price

#### Scenario: Only POS-owner group is taxable for PKP POS bundle
- **WHEN** the POS transaction owner is PKP and a bundle is split across multiple owners
- **THEN** only the bundle allocation posted to the POS-owner Sales document SHALL use a taxable bucket
- **AND** every other source-owner bundle allocation SHALL use a non-tax bucket regardless of that source owner's PKP or stock-tax state

#### Scenario: Non-PKP POS owner produces non-tax bundle groups
- **WHEN** the POS transaction owner is non-PKP
- **THEN** all owner groups for that bundled row SHALL be non-tax

### Requirement: Split posting MUST reconcile totals exactly
The system MUST use minor-unit-safe arithmetic so parent residual plus fixed component allocations equals the captured customer bundle amount and aggregate owner Sales totals equal the POS checkout total.

#### Scenario: Configured bundle price reconciles
- **WHEN** a bundle uses its configured parent row price
- **THEN** parent residual plus all component allocations SHALL equal that captured row amount
- **AND** all generated owner-group grand totals SHALL equal the checkout grand total

#### Scenario: Overridden bundle price reconciles through parent residual
- **WHEN** the cashier overrides the bundled parent row price
- **THEN** component allocations SHALL remain fixed
- **AND** only the parent residual SHALL change
- **AND** owner-group totals SHALL reconcile to the overridden captured amount

#### Scenario: Quantity and rounding reconcile across owners
- **WHEN** a multi-quantity bundle requires allocation across multiple owner/location groups
- **THEN** component quantities and amounts SHALL be distributed without losing or duplicating minor units
- **AND** aggregate quantities and money SHALL equal the captured checkout values exactly

### Requirement: Posted tax persistence SHALL remain consistent with planned source-owner tax policy
Finalize SHALL persist included tax only on the bundled allocation belonging to the PKP POS transaction owner. Source-owner component Sales documents SHALL remain non-tax, and the customer tax summary SHALL equal tax extracted from the POS-owner taxable allocation rather than the full bundle price.

#### Scenario: Canonical three-owner bundle taxes parent owner only
- **WHEN** a PKP Setting 1 POS sells a `5,550,000` bundle with Setting 1 parent residual `5,475,000`, Setting 2 component allocation `50,000`, and Setting 3 component allocation `25,000`
- **THEN** tax SHALL be extracted only from `5,475,000`
- **AND** the Setting 2 and Setting 3 Sales documents SHALL persist zero tax
- **AND** all three Sales totals SHALL still reconcile to `5,550,000`

#### Scenario: Receipt tax reconciles to taxable internal allocation
- **WHEN** a split bundle receipt displays the full parent price and zero/free components
- **THEN** its tax summary SHALL equal the tax persisted for the POS-owner allocation
- **AND** it SHALL NOT extract tax from non-tax source-owner allocations
