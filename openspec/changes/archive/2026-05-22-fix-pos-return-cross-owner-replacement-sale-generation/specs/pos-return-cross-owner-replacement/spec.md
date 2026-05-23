## ADDED Requirements

### Requirement: Cross-Owner Replacement Uses Replacement Serial Owner Sale
The system SHALL detect product-replacement POS Return lines whose selected replacement serial is owned by a different setting than the original returned Sale line. The replacement serial owner MUST be resolved from `product_serial_numbers.location_id -> locations.setting_id`. For a cross-owner replacement, final approval MUST adjust the original Sale commercially for the returned item and MUST create a new Sale under the replacement serial owner for the replacement item.

#### Scenario: Cross-owner replacement creates replacement owner sale
- **WHEN** final approval executes a product-replacement POS Return line whose original source owner is Setting A
- **AND** the selected replacement serial is located at a location owned by Setting B
- **THEN** the system adjusts the original Setting A Sale for the returned item
- **AND** creates a new Setting B Sale for the replacement item
- **AND** does not attach the Setting B replacement serial to the Setting A Sale dispatch lineage

#### Scenario: Missing replacement serial owner blocks approval
- **WHEN** final approval evaluates a product-replacement POS Return line whose replacement serial has no resolvable location setting owner
- **THEN** the system blocks final approval with a clear replacement owner message
- **AND** no Sale, dispatch, stock, serial, payment, POS Return, or Sales Return mutation occurs

### Requirement: Cross-Owner Replacement Keeps Strict Product Identity
The system SHALL allow cross-owner product replacement only when the selected replacement serial belongs to the same `product_id` as the returned POS Return line. The system MUST NOT match cross-owner replacement by product code, product name, SKU text, or other equivalence rules for this change.

#### Scenario: Same product id replacement is accepted
- **WHEN** a product-replacement POS Return line for `product_id X` selects an active replacement serial whose `product_id` is also `X`
- **THEN** the replacement identity check passes

#### Scenario: Different product id replacement is blocked
- **WHEN** a product-replacement POS Return line for `product_id X` selects an active replacement serial whose `product_id` is not `X`
- **THEN** the system blocks replacement selection or final approval with a same-product message
- **AND** no replacement-owner Sale or dispatch is created

### Requirement: Original Sale Is Commercially Adjusted For Cross-Owner Replacement
For cross-owner product replacement, the system SHALL treat the original Sale side as a commercial return for the returned quantity. The system MUST reduce the original Sale detail quantity and amount, reduce active original dispatch quantity, reconcile active Sale payments to the adjusted Sale total, receive returned stock and serials to the original source location, and preserve returned serial lineage as returned on the original Sale.

#### Scenario: Original sale is reduced for cross-owner replacement
- **WHEN** final approval executes a cross-owner replacement for one serial-tracked item sold on Setting A
- **THEN** the Setting A Sale detail quantity and amount are reduced by the returned quantity and amount
- **AND** the original dispatch active quantity is reduced
- **AND** the returned serial is received back to the original source location and shown as returned lineage

#### Scenario: Original sale payment is reconciled
- **WHEN** final approval reduces the original Sale total for a cross-owner replacement
- **THEN** active Sale payments for the original Sale are reconciled so active paid amount does not exceed the adjusted Sale total
- **AND** invalidated or split payment evidence remains auditable

### Requirement: Replacement Owner Sale Copies Original Header And Payment Context
For cross-owner product replacement, the system SHALL create the replacement-owner Sale using the replacement serial owner's setting and the original Sale's date, customer/header context, payment method context, and proportional adjusted payment amount. The generated Sale MUST receive a new reference under the replacement owner and MUST be linked to the POS Return approval context for audit.

#### Scenario: Replacement owner sale copies header context
- **WHEN** final approval creates a Setting B replacement-owner Sale from an original Setting A Sale
- **THEN** the new Sale uses Setting B as `setting_id`
- **AND** copies the original Sale date and relevant customer/header context
- **AND** uses a newly generated Setting B Sale reference
- **AND** records an audit note or link back to the POS Return and original Sale

#### Scenario: Replacement owner sale receives adjusted payment
- **WHEN** final approval creates a replacement-owner Sale for a returned item amount
- **THEN** the new Sale has SalePayment evidence for the adjusted amount allocated to the replacement item
- **AND** the new Sale paid amount, due amount, and payment status reflect that payment evidence

### Requirement: Replacement Serial Dispatch Uses Actual Owner Location
For cross-owner product replacement, the system SHALL dispatch the replacement serial from its actual current location and SHALL attribute stock mutation transactions to that location's owner setting. The system MUST mark the replacement serial sold under the generated replacement-owner Sale dispatch lineage.

#### Scenario: Replacement serial stock leaves replacement owner location
- **WHEN** final approval dispatches a Setting B replacement serial for a cross-owner replacement
- **THEN** stock is decremented from the replacement serial's current Setting B location
- **AND** the stock transaction `setting_id` is Setting B
- **AND** the replacement serial is marked sold and linked to the Setting B dispatch detail

#### Scenario: Replacement stock unavailable blocks approval
- **WHEN** final approval finds that the replacement serial or replacement stock is no longer active and available at its resolved owner location
- **THEN** the system blocks approval
- **AND** no original Sale correction or replacement-owner Sale mutation persists

### Requirement: Cross-Owner Replacement Approval Is Atomic
The system SHALL execute cross-owner replacement approval in one database transaction across POS Return, linked Sales Return, original Sale correction, generated replacement-owner Sale, Sale payments, dispatches, ProductStock, ProductSerialNumber, stock transactions, serial tracking, and audit fields. Any failure MUST roll back the entire approval.

#### Scenario: Generated sale failure rolls back original correction
- **WHEN** final approval begins a cross-owner replacement and fails while creating the replacement-owner Sale, payment, dispatch, stock transaction, or serial lineage
- **THEN** the original Sale remains unchanged
- **AND** the POS Return remains pending approval
- **AND** no partial replacement-owner Sale, dispatch, payment, stock, or serial mutation persists
