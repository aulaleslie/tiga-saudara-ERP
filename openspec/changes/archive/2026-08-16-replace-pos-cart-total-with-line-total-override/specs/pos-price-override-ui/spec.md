## ADDED Requirements

### Requirement: Each eligible POS row MUST expose two visually distinct monetary actions
Each eligible customer-facing POS row SHALL expose an `Ubah Harga Satuan` action and an `Ubah Total Baris` action within that individual row's action area. The two controls MUST be visually distinct and unambiguous, and each MUST identify the row it belongs to.

#### Scenario: Eligible row renders both actions
- **WHEN** an ordinary, packed, or billable bundle-parent row is rendered
- **THEN** the row MUST display an `Ubah Harga Satuan` action
- **AND** the row MUST display an `Ubah Total Baris` action
- **AND** each action MUST be distinguishable by label or icon and MUST target only that row

#### Scenario: Bundle components expose neither action
- **WHEN** a bundle component row is rendered beneath its billable parent
- **THEN** the component MUST NOT expose either monetary action
- **AND** its commercial price and subtotal MUST remain zero

#### Scenario: No monetary action appears at cart scope
- **WHEN** the cart or payment grand-total area is rendered
- **THEN** it MUST display the calculated grand total as read-only
- **AND** MUST NOT expose an `Ubah Harga Satuan`, `Ubah Total Baris`, or cart-wide `Ubah Total` action

### Requirement: The two row actions MUST use fully separate client state
The unit-price interaction and the row-total interaction SHALL use separate modal identifiers, form state, endpoints, JavaScript handlers, labels, and error handling. Neither interaction MAY reuse the other's DOM state.

#### Scenario: Modals are separately identified
- **WHEN** the POS sell view is rendered
- **THEN** the unit-price modal and the row-total modal MUST have distinct element identifiers
- **AND** neither MUST reuse the retired ambiguous `price_override` identifiers for both operations

#### Scenario: Each modal shows row identity and its own current value
- **WHEN** either monetary modal is opened from a row
- **THEN** it MUST display the selected product and row identity
- **AND** the unit-price modal MUST display and edit the row's current unit price
- **AND** the row-total modal MUST display and edit the row's current authoritative final total

#### Scenario: Each action posts to its own endpoint
- **WHEN** the user submits the unit-price modal
- **THEN** the request MUST target the unit-price endpoint with the `LINE_UNIT_PRICE_OVERRIDE` contract
- **AND** submitting the row-total modal MUST target the row-total endpoint with the `LINE_TOTAL_OVERRIDE` contract

### Requirement: Both row modals MUST validate input before enabling submission
Each modal SHALL accept a numeric value greater than or equal to zero, reject blank, nonnumeric, negative, and unchanged values with its own error surface, capture an optional reason, and retain authoritative server-side validation.

#### Scenario: Invalid input disables submission
- **WHEN** a user enters a blank, nonnumeric, negative, or unchanged value in either modal
- **THEN** that modal MUST display its own error message
- **AND** MUST keep its submission control disabled

#### Scenario: Errors do not leak between modals
- **WHEN** one modal displays a validation error and the other modal is opened
- **THEN** the second modal MUST NOT display the first modal's error state

### Requirement: Both row modals MUST use the established approval lifecycle
Each interaction SHALL use the existing `ApprovalManager` request, check, and continue flow with its own action type, target line, requested value, and row fingerprint. Approval state MUST be keyed by both line and action type.

#### Scenario: Approval state stays keyed by line and action
- **WHEN** one row has a pending unit-price request and a pending row-total request
- **THEN** each control MUST display only its own approval state
- **AND** neither MUST display the other's `Periksa Persetujuan` state
- **AND** no other row's controls MUST display either request

## REMOVED Requirements

### Requirement: Price Cell MUST Render Edit Trigger With Approval State
**Reason**: The row price cell now renders two distinct monetary actions rather than a single unit-price trigger, each with its own line-and-action-keyed approval state.

**Migration**: Render the authoritative unit price and row total with separate `Ubah Harga Satuan` and `Ubah Total Baris` controls, each carrying its own approval state.

### Requirement: Price Edit Button Click MUST Open Price Override Modal
**Reason**: A single ambiguous trigger cannot open two distinct editors.

**Migration**: Open the unit-price modal from the `Ubah Harga Satuan` control and the row-total modal from the `Ubah Total Baris` control.

### Requirement: Price Override Modal Submit MUST Use ApprovalManager.wrapAction
**Reason**: There are now two supervised monetary actions with distinct action types and requested values.

**Migration**: Continue using the established approval manager lifecycle, with each modal supplying its own action type, target line, requested value, and row fingerprint.

### Requirement: Price Override Modal MUST Enforce Client-Side Validation Before Enabling the Submission Button
**Reason**: Validation now belongs to two independent inputs compared against two different current values.

**Migration**: Validate the unit-price input against the current unit price and the row-total input against the current authoritative row total, each with its own error surface.

### Requirement: Price Override Modal MUST Be An Extracted Blade Partial
**Reason**: One shared partial cannot serve two operations without reintroducing ambiguous state.

**Migration**: Use two extracted modal partials following the existing POS modal organization, with distinct identifiers.
