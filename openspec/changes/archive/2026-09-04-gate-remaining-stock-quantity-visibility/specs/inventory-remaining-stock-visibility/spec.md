## ADDED Requirements

### Requirement: System SHALL define an `inventory.view_remaining_stock` permission governing numeric stock-quantity visibility
The permission registry SHALL include `inventory.view_remaining_stock` as a standalone permission, independent of `pos.sell` and `pricePoints.access`, used by any surface that displays an exact remaining-stock quantity.

#### Scenario: Permission is registered independently of page-access permissions
- **WHEN** the permission registry is inspected
- **THEN** `inventory.view_remaining_stock` SHALL exist as its own entry
- **AND** it SHALL NOT be implied by holding `pos.sell` or `pricePoints.access` alone

### Requirement: Numeric stock-quantity data SHALL be omitted from responses for users without the permission
Any backend code path that assembles product data for POS product search or the Price Points browser SHALL omit the numeric quantity field (`available_qty` and any formatted/denominated equivalent) entirely from the data returned to the view/client when the acting user lacks `inventory.view_remaining_stock`. The field SHALL NOT be set to `null` or `0` as a substitute for omission.

#### Scenario: User without permission receives no quantity field
- **WHEN** a user lacking `inventory.view_remaining_stock` triggers POS product search or loads the Price Points browser
- **THEN** each product entry in the response/component data SHALL NOT include `available_qty` or a formatted quantity string

#### Scenario: User with permission receives the quantity field as today
- **WHEN** a user holding `inventory.view_remaining_stock` triggers POS product search or loads the Price Points browser
- **THEN** each product entry SHALL include `available_qty` (and formatted/denominated value where applicable) exactly as currently computed

### Requirement: The stock-quantity display element SHALL be entirely absent for users without the permission
Product cards in POS product search and the Price Points browser SHALL render the "Stok" label-and-value element only when the underlying quantity field is present in the data supplied to the view. When the field is absent, no partial element (label only, blank value, placeholder) SHALL be rendered in its place.

#### Scenario: Stok element fully hidden for unprivileged user
- **WHEN** a product card is rendered for a user without `inventory.view_remaining_stock`
- **THEN** the card SHALL NOT display a "Stok" label, value, or placeholder in that position

#### Scenario: Stok element rendered as today for privileged user
- **WHEN** a product card is rendered for a user with `inventory.view_remaining_stock`
- **THEN** the card SHALL display the "Stok" label and numeric/denominated value exactly as before this change

### Requirement: Stock-state visual indicators SHALL be unaffected by the permission
Out-of-stock ("Stok Kosong") and service/non-stock-managed badges, disabled-card styling, and card non-selectability SHALL be computed and rendered identically regardless of whether the acting user holds `inventory.view_remaining_stock`.

#### Scenario: Out-of-stock badge shown to unprivileged user without quantity
- **WHEN** a stock-managed product has `available_qty <= 0` and the acting user lacks `inventory.view_remaining_stock`
- **THEN** the card SHALL still render disabled styling and the "Stok Kosong" badge
- **AND** the card SHALL still be non-selectable
- **AND** no numeric quantity SHALL be shown

#### Scenario: Service badge shown to unprivileged user without quantity
- **WHEN** a non-stock-managed product is rendered for a user lacking `inventory.view_remaining_stock`
- **THEN** the card SHALL still render the "Service" badge
- **AND** no numeric quantity or `-` quantity placeholder SHALL be shown in the Stok element's place
