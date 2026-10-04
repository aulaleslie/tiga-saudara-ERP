# pos-cart-management Specification

## Purpose
This specification defines the requirements for POS cart management, including item additions, quantity updates, and inventory validation.

## Requirements
### Requirement: Indonesian Cart Messages
Exception messages in POS cart operations must be in Bahasa Indonesia.

#### Scenario: Invalid Quantity
- **WHEN** Adding item with quantity less than 1
- **THEN** The system returns 'Kuantitas harus minimal 1.' instead of 'Quantity must be at least 1.'

#### Scenario: Stock Unavailable
- **WHEN** Requested quantity exceeds available stock
- **THEN** The system returns 'Kuantitas yang diminta melebihi stok tersedia untuk lokasi penjualan yang dikonfigurasi.'

### Requirement: Unified Transaction Record
The POS transaction finalization must persist exactly one `PosTransaction` record per checkout, regardless of how many settings provide stock for the line items.

#### Scenario: Cross-Tenant Sale Unification
- **WHEN** A checkout contains items from Setting A and Setting B.
- **THEN** Only one `PosTransaction` record is created, owned by the active session's Setting.
- **THEN** The transaction contains all lines from both settings.

### Requirement: Serial Handoff for Bundle Parent
The system MUST preserve a scanned serial number when a product requires bundle selection, and automatically append that serial number to the resulting cart line after the bundle is selected.

#### Scenario: Scan Serial for Bundle Parent
- **WHEN** user scans a serial number for a product that is a bundle parent
- **THEN** the system prompts for bundle selection while preserving the serial number in temporary state
- **AND** after the user selects a bundle, the serial number is automatically appended to the newly created bundle line in the cart.

#### Scenario: Continue Without Bundle (Normal)
- **WHEN** user chooses to "Continue Normal" for a bundle parent that was scanned by serial
- **THEN** the system adds the product without a bundle and automatically appends the serial number.

### Requirement: POS cart line targeting SHALL include bundle state
When adding or updating a POS cart line for a bundle-parent product, the system SHALL identify the target cart row by product and bundle state. A selected bundle id, a different selected bundle id, and no selected bundle MUST be treated as distinct line identities.

#### Scenario: Same product and same selected bundle merges
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and chooses Bundle A again
- **THEN** the system MUST target the existing Product A with Bundle A row
- **AND** the system MUST increment quantity or append the scanned serial on that row according to the product's serial tracking behavior

#### Scenario: Same product and different selected bundle does not merge
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and chooses Bundle B
- **THEN** the system MUST create or target a Product A with Bundle B row
- **AND** the system MUST NOT increment or append serials on the Product A with Bundle A row

#### Scenario: Same product without bundle does not merge into selected bundle
- **WHEN** the cart contains Product A with Bundle A
- **AND** the cashier adds Product A and explicitly continues without a bundle
- **THEN** the system MUST create or target a Product A row without bundle metadata
- **AND** the system MUST NOT increment or append serials on the Product A with Bundle A row

#### Scenario: Bundle-aware rows coexist in one cart
- **WHEN** the cashier adds Product A with Bundle A, Product A with Bundle B, and Product A without a bundle
- **THEN** the cart snapshot MUST expose three distinct rows for the same parent product
- **AND** each row MUST retain its own quantity and assigned serial list

### Requirement: Packed line merge and re-pack on repeated scans
The system SHALL compute a packed line's merge key from product, tax, and customer tier (+ bundle if applicable), excluding the conversion ID and blended unit price. Repeated additions of the same product+tier SHALL coalesce into a single line whose total quantity is re-packed from scratch. The system SHALL NOT price an incremental quantity in isolation and add it to an existing packed line. A base/product-search add and a box-scan add of the same product+tier coalesce into one PACKED line and re-pack the combined base quantity.

Note: This relies on the invariant of one box-conversion per product. If that ever changes, the merge key must be revisited.

#### Scenario: Scanning the same box twice coalesces into one re-packed line
- **WHEN** a cashier scans the same box barcode twice (factor 5)
- **THEN** a single line with quantity 10 exists
- **AND** the line total is computed by re-packing 10 base units, not by adding two independent 5-unit prices

#### Scenario: Product-search add and box-scan add coalesce into one re-packed line
- **WHEN** a cashier adds a product via search (qty 5) and then scans the same product's box barcode (factor 5)
- **THEN** a single line with quantity 10 exists
- **AND** the line total is computed by re-packing 10 base units, dropping the conversion-vs-search distinction

#### Scenario: Merge key ignores the blended price
- **WHEN** two packed additions of the same product+tier produce different blended unit prices at their intermediate quantities
- **THEN** they still share a merge key and coalesce into one line

### Requirement: Snapshot merge-key parity for packed lines
When persisting or reloading a POS transaction, the system SHALL compute the packed-line merge key using the same fields as the cart service (product, tax, tier; excluding conversion and blended price) so that reloaded lines coalesce consistently with the cart.

#### Scenario: Reloaded packed line coalesces consistently
- **WHEN** a persisted transaction with a packed line is reloaded into the cart
- **THEN** the reloaded line's merge key matches the cart service's computed merge key for the same product+tier

### Requirement: Initial ordinary line price reflects selected customer tier
When a product is added as an ordinary line (not a bundle and not a packed/box-priced product) to a cart that already has a customer selected, the system SHALL set the line's initial unit price from that customer's tier: the tier 1 price for WHOLESALER and the tier 2 price for RESELLER. If the applicable tier price is zero or unset, or the customer has no tier, the system SHALL use the base sale price, retaining the existing fallback to the product's own price when no per-setting price exists. A line priced by a tier price SHALL be identified as tier-priced. Bundle lines and packed-line pricing SHALL be unaffected by this requirement.

#### Scenario: Wholesaler selected before adding product
- **WHEN** a WHOLESALER customer is selected on an empty cart and an ordinary product with a tier 1 price is added
- **THEN** the new line's unit price equals the product's tier 1 price and the line is identified as tier-priced

#### Scenario: Reseller selected before adding product
- **WHEN** a RESELLER customer is selected on an empty cart and an ordinary product with a tier 2 price is added
- **THEN** the new line's unit price equals the product's tier 2 price

#### Scenario: Tier price not configured
- **WHEN** a tier customer is selected and the product's applicable tier price is zero or unset
- **THEN** the new line's unit price equals the base sale price

#### Scenario: Customer without tier
- **WHEN** a customer without a tier is selected and an ordinary product is added
- **THEN** the new line's unit price equals the base sale price

#### Scenario: Conversion unit for tier customer
- **WHEN** a tier customer is selected and an ordinary product is added using a conversion unit
- **THEN** the line's unit price is the tier price per base unit and no conversion pricing is applied

#### Scenario: Repeated add merges
- **WHEN** the same ordinary product is added twice with the same tier customer selected
- **THEN** both additions coalesce into one line at the tier unit price

#### Scenario: Bundle unaffected
- **WHEN** a tier customer is selected and a bundle is added
- **THEN** the bundle line's unit price is the bundle sale price, unchanged by tier

### Requirement: POS quantity changes SHALL refresh the affected row and cart summary consistently
After a successful quantity change, the POS sell screen MUST display the authoritative quantity, line pricing, cart totals, approval state, and checkout availability. Unchanged cart rows MUST retain their current browser interaction state when the cart's row structure and identity have not changed.

#### Scenario: Quantity increases on an existing row
- **WHEN** a cashier increases one row's quantity and the server accepts it
- **THEN** that row and the cart totals MUST reflect the returned cart state
- **AND** unrelated rows MUST remain usable without being replaced

#### Scenario: Quantity reduction is approved
- **WHEN** an authorized quantity reduction completes
- **THEN** the reduced row, approval indicator, totals, and checkout controls MUST reflect the returned cart state

#### Scenario: Quantity mutation fails
- **WHEN** the server rejects a quantity change for stock, authorization, or another validation reason
- **THEN** the edited quantity MUST revert to the last accepted value
- **AND** no unconfirmed cart total MUST be displayed as accepted

#### Scenario: Cart structure changes during response
- **WHEN** the returned cart has a different set or order of row identities from the displayed cart
- **THEN** the screen MUST render the complete returned cart state so no row or total is stale

#### Scenario: Packed pricing or serial state changes
- **WHEN** a quantity change recalculates packed pricing or changes serial completeness
- **THEN** the affected row and checkout controls MUST show the authoritative returned values

