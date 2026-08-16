## ADDED Requirements

### Requirement: Correction is restricted to import-origin, unfulfilled products
The system SHALL only allow the UOM correction command to execute for a product whose entire transaction ledger contains no `BUY`-type transaction and no dispatch/fulfillment history, and SHALL block execution otherwise.

#### Scenario: Product with dispatch history is refused
- **WHEN** an operator runs the correction command for a product that has at least one `DISPATCH`-type transaction
- **THEN** the system SHALL refuse to execute and SHALL not change any product, stock, price, or document data

#### Scenario: Product with a paid but undispatched Sale is refused
- **WHEN** an operator runs the correction command for a product referenced by a Sale with `paid_amount > 0`, regardless of that Sale's status
- **THEN** the system SHALL refuse to execute and SHALL not change any product, stock, price, or document data

#### Scenario: Product with any BUY-lineage transaction is refused
- **WHEN** an operator runs the correction command for a product that has at least one `BUY`-type transaction
- **THEN** the system SHALL refuse to execute, since receipt-traceable stock must instead go through the existing received-purchase UOM normalization workflow

#### Scenario: Import-origin product with no fulfillment history is eligible
- **WHEN** an operator runs the correction command for a product whose only transaction history is adjustment-type (`ADJ`) and which has no dispatched, returned, or paid Sale, and no `BUY`-type transaction
- **THEN** the system SHALL proceed to the remaining eligibility checks

### Requirement: Ledger self-consistency is verified before mutation
The system SHALL verify that the product's live stock exactly matches the running balance recorded by its own transaction ledger, for every location and globally, before performing any mutation.

#### Scenario: Live stock matches ledger running balance
- **WHEN** the most recent transaction for the product at every location, and globally, has an `after_quantity`/`after_quantity_at_location` equal to the current live `product_stocks`/`product_quantity` value
- **THEN** the system SHALL treat the ledger as trustworthy and proceed

#### Scenario: Live stock has drifted from the ledger
- **WHEN** any location's or the product's global current quantity does not match the most recent transaction's recorded after-quantity
- **THEN** the system SHALL refuse to execute and SHALL report which location or global value is inconsistent

### Requirement: Broken stock blocks correction
The system SHALL refuse to execute the correction if the product has any non-zero broken quantity, in any bucket, at any location.

#### Scenario: Product has broken stock
- **WHEN** any `product_stocks` row for the product has a non-zero `broken_quantity`, `broken_quantity_tax`, or `broken_quantity_non_tax`
- **THEN** the system SHALL refuse to execute and SHALL not multiply any quantity for that product

### Requirement: Correction rebases live stock and cost basis without reading purchase history
The system SHALL compute the corrected quantities and cost basis from the product's current live values only, and SHALL NOT read or write `purchase_details` quantities as part of the correction.

#### Scenario: Quantities are multiplied by the stated factor
- **WHEN** an eligible correction executes with a given factor
- **THEN** the system SHALL multiply `products.product_quantity`, every `product_stocks` quantity bucket for the product, and the originating adjustment transaction's own quantity fields, by that factor

#### Scenario: Cost basis is divided by the stated factor
- **WHEN** an eligible correction executes with a given factor and the product has a non-null average or last purchase price in any setting
- **THEN** the system SHALL divide each such price by that factor and SHALL preserve higher internal precision before applying display rounding

#### Scenario: Base unit is updated
- **WHEN** an eligible correction executes
- **THEN** the system SHALL set the product's unit and base unit to the target unit supplied by the operator

### Requirement: Correction is refused when unhandled complexity is detected
The system SHALL refuse to execute the correction, rather than attempt a partial or best-effort mutation, when the product has any existing unit conversion row, a non-null barcode, or stock/price footprint in more than one setting.

#### Scenario: Product has an existing unit conversion
- **WHEN** the product being corrected already has one or more `product_unit_conversions` rows
- **THEN** the system SHALL refuse to execute and SHALL report that existing conversions are not supported by this correction path

#### Scenario: Product has a barcode
- **WHEN** the product being corrected has a non-null `barcode` value
- **THEN** the system SHALL refuse to execute and SHALL report that barcode migration is not supported by this correction path

#### Scenario: Product has footprint in more than one setting
- **WHEN** the product being corrected has stock or price records in more than one setting
- **THEN** the system SHALL refuse to execute and SHALL report the additional setting(s) found

### Requirement: Undispatched, unpaid documents referencing the product are removed
The system SHALL, as part of an eligible correction, remove every POS transaction and every Sale that references the product and has no dispatch and no recorded payment, and SHALL report each removed document.

#### Scenario: Draft or loaded POS cart is removed
- **WHEN** an eligible correction executes and a POS transaction with status `DRAFT` or `LOADED` has a line referencing the product
- **THEN** the system SHALL delete that entire POS transaction and its lines, and SHALL include its reference, status, and owner in the correction report

#### Scenario: Undispatched, unpaid Sale is removed
- **WHEN** an eligible correction executes and a Sale referencing the product has a status other than `DISPATCHED`, `RETURNED`, or `RETURNED PARTIALLY`, and a `paid_amount` of zero
- **THEN** the system SHALL delete that Sale and its details referencing the product, and SHALL include its reference, status, and customer in the correction report

#### Scenario: No stock compensation is needed for removed documents
- **WHEN** the system removes a POS transaction or Sale as part of a correction
- **THEN** the system SHALL NOT adjust `product_stocks` or `product_quantity` as a result of the removal, since undispatched documents never reserved or decremented stock

### Requirement: Correction requires an explicit reason and produces an immutable audit record
The system SHALL require a non-empty reason to execute the correction, and SHALL persist an immutable audit record of the correction upon successful execution.

#### Scenario: Missing reason blocks execution
- **WHEN** an operator attempts to execute the correction without supplying a reason
- **THEN** the system SHALL reject the request without changing any data

#### Scenario: Successful correction is audited
- **WHEN** the correction executes successfully
- **THEN** the system SHALL persist a record containing the product, old and new unit, factor, before and after quantities and cost basis, reason, acting user, timestamp, and the list of documents removed

### Requirement: Operator can preview the correction without mutating data
The system SHALL support a dry-run mode that reports the full eligibility outcome and, if eligible, the projected before/after impact and the list of documents that would be removed, without performing any mutation.

#### Scenario: Dry run on an eligible product
- **WHEN** an operator runs the correction command in dry-run mode for an eligible product
- **THEN** the system SHALL display the projected quantity and cost-basis changes and the documents that would be removed, and SHALL NOT change any data

#### Scenario: Dry run on an ineligible product
- **WHEN** an operator runs the correction command in dry-run mode for a product that fails any eligibility check
- **THEN** the system SHALL display the specific reason(s) for ineligibility and SHALL NOT change any data
