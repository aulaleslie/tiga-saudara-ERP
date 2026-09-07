## ADDED Requirements

### Requirement: POS SHALL require bundle selection for bundle-parent products before adding a bundled line
When unit choice is required, the POS sell flow SHALL complete unit selection before showing bundle options. The POS sell flow SHALL detect when a selected product is a bundle parent and MUST present bundle options before creating a bundled cart line. The cashier MUST be able to select an available bundle or explicitly continue without a bundle.

#### Scenario: Cashier selects a bundle for a bundle-parent product
- **WHEN** the cashier selects a product whose POS search result indicates it is a bundle parent
- **THEN** the POS shell fetches the available bundles for that parent product
- **AND** the cashier can choose one bundle before the cart line is created

#### Scenario: Cashier continues without a bundle
- **WHEN** the cashier selects a product whose POS search result indicates it is a bundle parent and chooses to continue without a bundle
- **THEN** the POS shell creates a normal parent product cart line
- **AND** the resulting cart line records that bundle selection was explicitly skipped

#### Scenario: Non-bundle product add remains unchanged
- **WHEN** the cashier selects a product that is not a bundle parent
- **THEN** the POS shell completes any required unit choice and adds the product through the existing normal cart flow without showing bundle selection UI

## ADDED Requirements

### Requirement: POS bundle additions SHALL preserve the selected unit quantity
The POS SHALL carry the selected conversion through bundle choice and explicit no-bundle continuation. A selected conversion factor SHALL multiply the parent quantity once; every bundle component quantity SHALL scale by that resulting bundle count. Existing bundle price, stock-managed behavior, serial validation, and bundle-specific merge rules SHALL remain authoritative. The bundle picker SHALL show the selected unit, resulting bundle count, and clearly labeled per-bundle sale price.

#### Scenario: BOX adds twelve bundles
- **WHEN** a cashier chooses BOX factor 12 and then a bundle containing 2 units of a child product per bundle
- **THEN** the add contributes 12 bundles and 24 child units
- **AND** existing bundle pricing calculates the charge for 12 bundles
- **AND** stock and serial requirements follow the multiplied quantities

#### Scenario: Explicit no-bundle retains BOX
- **WHEN** a cashier chooses BOX factor 12 and continues without a bundle
- **THEN** the ordinary product addition contributes 12 base units using existing normal pricing

#### Scenario: Repeated bundle add retains existing merge rules
- **WHEN** a cashier completes another BOX factor 12 add with the same bundle intent
- **THEN** the matching row increments by 12 when existing merge rules allow it
- **AND** different bundle selections remain separate

#### Scenario: Bundle preview communicates quantity
- **WHEN** bundle selection opens after BOX factor 12 is chosen
- **THEN** the picker shows that the selection will add 12 bundles and labels the authoritative price per bundle

#### Scenario: Multiplied component stock or serials are insufficient
- **WHEN** a factor-based bundle addition requires more component stock or serial assignments than available or supplied
- **THEN** existing stock and checkout serial validation enforce the full multiplied requirement
- **AND** checkout does not silently reduce the quantity or deduct components twice
