## ADDED Requirements

### Requirement: Conversion enablement is independent per business and workflow
The system SHALL store independent sales and purchase enablement for each conversion in each business, defaulting both to enabled. Product conversion editing SHALL display Sales enabled and Purchase enabled controls for the current business. Updating a control MUST NOT change another business's controls or price, the other workflow's control, or the shared conversion definition.

#### Scenario: Disable sales in one business
- **WHEN** business A disables sales for BOX while business B retains defaults
- **THEN** A's BOX sales flag SHALL be false and purchase flag SHALL remain true
- **AND** B's flags and price SHALL remain unchanged

#### Scenario: Existing records and missing business rows
- **WHEN** existing data is migrated or a business has no conversion-price record
- **THEN** both enablement checks SHALL default to enabled
- **AND** existing missing-price behavior SHALL remain unchanged

#### Scenario: Controls survive validation and row changes
- **WHEN** a disabled control is submitted with unrelated invalid form data or another conversion row is added or removed
- **THEN** the control SHALL retain its explicit false value with the correct conversion identity

### Requirement: Shared creation and local updates preserve enablement
The system SHALL retain shared conversion metadata and seed initial conversion prices across all existing businesses with both flags enabled. Explicit creation-time disabled choices SHALL apply only to the acting business. Existing price-only updates and backfills MUST NOT reset stored flags; omitted existing-row controls SHALL preserve their stored values. Re-enabling SHALL retain the stored price.

#### Scenario: Add conversion from product edit
- **WHEN** business A creates BOX priced at 110000
- **THEN** all existing businesses SHALL receive its initial price and enabled defaults
- **AND** an explicit sales-disable choice SHALL affect A only

#### Scenario: Price-only update preserves disabled status
- **WHEN** a price-only write updates an existing disabled conversion price
- **THEN** its disabled flags SHALL remain unchanged

### Requirement: Sales excludes disabled conversion prices and barcodes
Sales and POS SHALL exclude sales-disabled conversions for the acting business from new automatic conversion-price selection and SHALL reject their conversion barcode scans before cart mutation. Exact barcode search and alternate entry paths MUST NOT bypass rejection by adding the base product. Other enabled conversions and normal product pricing SHALL retain existing behavior. Existing persisted transaction evidence SHALL remain unchanged.

#### Scenario: Disabled BOX price is skipped
- **WHEN** a new automatically priced sale contains twelve PCS and its only BOX conversion is sales-disabled
- **THEN** pricing SHALL use existing normal unit pricing without the BOX conversion price

#### Scenario: Disabled conversion scan is rejected
- **WHEN** a cashier scans or submits an exact search for a sales-disabled conversion barcode
- **THEN** the system SHALL explain that the conversion is disabled for sales in the current business
- **AND** the cart SHALL remain unchanged, including when an existing line could otherwise be incremented

#### Scenario: Other business can scan
- **WHEN** the same conversion is sales-enabled in business B
- **THEN** B SHALL retain existing conversion scan and pricing behavior

#### Scenario: Base barcode remains usable
- **WHEN** the product's base barcode is scanned while BOX is sales-disabled
- **THEN** existing base-product entry SHALL remain available without the disabled BOX price candidate
