## Purpose

Allow operators to correct a product's base unit of measure (UOM) from a product's detail page without requiring a specific purchase document context.
## Requirements
### Requirement: Authorized users can preview a product base-UOM correction
The system SHALL allow only an authorized user to preview one stock-managed, non-serial product's correction from its current base UOM to a different target Unit. Product and target Unit selection SHALL be searchable; the target Unit SHALL be searched from the Unit catalog and SHALL NOT be limited to existing product conversions. The operator SHALL provide only the target Unit, one positive factor expressing `1 current base UOM = factor target UOM`, and a reason.

#### Scenario: Product created and received in BOX must become PCS
- **WHEN** an authorized user selects a product whose current base UOM is `BOX`, target Unit `PCS`, and factor `10`
- **THEN** the system SHALL propose a correction from `10 BOX` to `100 PCS`
- **AND** it SHALL not require an already configured `BOX -> PCS` conversion
- **AND** it SHALL create or retain `BOX -> PCS` as the product conversion after successful execution

#### Scenario: Large purchase has searchable selectors
- **WHEN** a Purchase contains more than one hundred products or Units are not already configured as conversions
- **THEN** the operator SHALL be able to search product by name, code, or barcode and target Unit by name or short name
- **AND** after product selection the system SHALL show only that product's relevant purchase/receipt rows

### Requirement: Execution is complete, product-wide, and location-aware
The system SHALL execute only when every old-base purchase/receipt fact in the permitted product scope is selected and fully received, or is void/cancelled without stock effect; every selected receiving detail has exactly one original `BUY` transaction; every current stock location/bucket is explainable by the selected receipt-created `BUY` rows; and no selected row was corrected previously.

#### Scenario: Several purchases and locations are corrected together
- **WHEN** selected receipts for one product were received into several locations
- **THEN** the system SHALL preserve each receipt location and show before/after quantity by location
- **AND** it SHALL rebase the product total and every `product_stocks` quantity, tax/non-tax, and broken-quantity bucket consistently

#### Scenario: Incomplete related purchase blocks base-UOM switch
- **WHEN** another old-base purchase line for the selected product is partially received or otherwise open
- **THEN** the system SHALL permit an informative preview
- **BUT** it SHALL block execution until that line is completed and included, or voided/cancelled without stock effect

#### Scenario: Unexplained stock blocks execution
- **WHEN** product stock in any location comes from an opening balance, import, transfer, adjustment, return, breakage, bundle use, or another unselected source
- **THEN** the system SHALL reject execution and identify the location and blocking source

### Requirement: Base-UOM correction has strict safety eligibility
The system SHALL reject execution for serial-tracked products; an other-setting physical inventory, purchase/receipt-history, or transaction footprint; dispatched or partially dispatched Sales; completed POS checkouts, including consumed bundle components; or any incompatible inventory movement. Sale/POS drafts, loaded POS carts, cancelled POS records, and non-dispatched Sales SHALL not alone block the correction.

#### Scenario: Other setting has only product price rows
- **WHEN** another setting has `ProductPrice` rows for the corrected product but has no stock, purchase/receipt history, or inventory transaction footprint for that product
- **THEN** the system SHALL permit the correction
- **AND** it SHALL divide that setting's `last_purchase_price` and `average_purchase_price` by the factor atomically
- **AND** it SHALL preserve that setting's sale, tier, and conversion selling prices
- **AND** it SHALL record each setting's purchase-cost before/after values in immutable audit evidence

#### Scenario: Other setting has physical or historical inventory footprint
- **WHEN** another setting has product stock, purchase/receipt history, or inventory transactions for the corrected product
- **THEN** the system SHALL reject execution and identify the affected setting(s)

#### Scenario: Completed outbound sale blocks correction
- **WHEN** the product has a dispatched standard Sale or completed POS checkout
- **THEN** the system SHALL reject execution without changing any product, purchase, receipt, transaction, stock, conversion, or price record

#### Scenario: Draft commercial activity is warned, not rewritten
- **WHEN** the product appears only in a Sale draft, POS draft, or loaded POS cart
- **THEN** the system SHALL not reject execution solely for that activity
- **AND** it SHALL warn that the draft requires review before it is completed
- **AND** it SHALL not alter the draft's prices, quantities, or historical values

### Requirement: Correction updates inventory and purchase cost in place
The system SHALL atomically change the product's primary and base UOM to the target Unit; multiply selected purchase/approved-receipt quantities, original `BUY` quantities, transaction buckets/snapshots, global product quantity, and per-location stock buckets by the factor; and rebuild global and location transaction snapshots chronologically. It SHALL preserve receipt locations and SHALL not create a compensating transaction.

#### Scenario: Legacy transaction match is missing or ambiguous
- **WHEN** a selected legacy receiving detail has zero or more than one original `BUY` candidate
- **THEN** the system SHALL reject the whole batch without partially changing data or creating a correction transaction

### Requirement: Supplier money remains invariant and sales prices remain untouched
The system SHALL preserve purchase header/line totals, taxes, discounts, payments, due amounts, supplier identity, and receipt monetary values. It SHALL recalculate normalized per-target-unit purchase cost, current average purchase price, and last purchase price in the active setting, and SHALL rebase `last_purchase_price` and `average_purchase_price` in every other price-only setting by the factor. It SHALL NOT change product sale price, tier prices, conversion sale prices, historical Sale/POS money, or sale HPP snapshots.

#### Scenario: Operator acknowledges proposed derived result
- **WHEN** the preview proposes a valid correction
- **THEN** it SHALL display quantity, per-location stock, purchase unit-cost/HPP, conversion/barcode, and rounding impacts
- **AND** execution SHALL require acknowledgement that the proposed inventory and purchase-cost results were reviewed
- **AND** acknowledgement that sales prices were deliberately not changed and must be reviewed before sale or dispatch

### Requirement: Conversion, barcode, and audit effects are safe and visible
The system SHALL migrate existing conversions based on the old base UOM only where their target factor, barcode, and uniqueness conditions can be determined safely. It SHALL block execution with a remediation reason for a collision or ambiguous barcode/unit meaning. It SHALL persist immutable old/new UOM, factor, conversion/barcode, selected-row, transaction, location, cost, acknowledgement, actor, time, and reason evidence.

#### Scenario: Completed correction is auditable from affected purchases
- **WHEN** a base-UOM correction completes
- **THEN** every affected purchase SHALL show a read-only audit summary containing old/new UOM, factor, base quantity, actor, time, reason, and batch reference

### Requirement: Candidate purchase/receipt rows default to fully selected
Once the operator has entered a target Unit and a positive factor, the system SHALL automatically load and pre-select every eligible candidate purchase/receipt row for the product in the active setting, while keeping each row's selection individually visible and togglable before preview.

#### Scenario: All eligible rows are preselected after factor entry
- **WHEN** an authorized user sets a valid target Unit and a positive factor for an eligible product
- **THEN** the system SHALL load that product's candidate purchase/receipt rows in the active setting
- **AND** it SHALL preselect all of them without requiring a manual "select all" action

#### Scenario: Operator can still deselect an individual row before preview
- **WHEN** candidate rows have been preselected
- **THEN** the operator SHALL be able to uncheck any individual row before requesting a preview
- **AND** the preview and execution SHALL reflect only the rows still selected at the time of submission

