## ADDED Requirements

### Requirement: Authorized users can preview an explicit received-purchase UOM normalization batch
The system SHALL allow only an authorized user to create and preview a batch for one stock-managed, non-serial product, one direct configured source-UOM-to-existing-base-UOM conversion, and explicitly selected purchase details and their approved receiving details in the active setting.

#### Scenario: Preview selected erroneous purchase lines
- **WHEN** an authorized user selects eligible fully or partially received purchase lines for the same product and conversion
- **THEN** the system SHALL show each selected source quantity, intended base quantity, supplier monetary value, receiving status, receipt location, original transaction match status, and projected current HPP

#### Scenario: Incomplete receipt remains previewable but cannot execute
- **WHEN** one selected purchase line is not fully received
- **THEN** the system SHALL retain the preview as incomplete
- **AND** the system SHALL prevent normalization execution until every selected line is fully received

### Requirement: Normalization execution has strict inventory-history eligibility
The system SHALL execute a batch only when every selected line is fully received through approved receiving details, the product is non-serial and stock-managed, each receiving detail has exactly one matched original `BUY` transaction, and no selected row has been normalized previously.

#### Scenario: Stock-affecting sale blocks execution
- **WHEN** the product has a dispatched or partially dispatched standard Sale, or a completed POS checkout that includes the product or its consumed bundle component
- **THEN** the system SHALL reject execution without changing any selected row

#### Scenario: Draft sales and POS carts do not block execution
- **WHEN** the product appears only in standard Sales that are not dispatched or in POS transactions that are draft, loaded, or cancelled
- **THEN** the system SHALL not reject execution solely because of those records

#### Scenario: Later inventory movement blocks execution
- **WHEN** the product has a purchase return, transfer, adjustment, breakage, replacement dispatch, import/initialization movement, or other inventory transaction after an affected receipt
- **THEN** the system SHALL reject execution and identify the blocking history in the result

### Requirement: Normalization updates selected receipt facts and original transaction facts atomically
The system SHALL, in one database transaction, convert selected purchase-detail and approved receiving-detail quantities to the product base UOM, update their uniquely matched original `BUY` transaction rows, and rebuild affected stock quantity snapshots in chronological order.

#### Scenario: Multi-purchase normalization preserves chronological transaction snapshots
- **WHEN** a batch includes fully received erroneous lines from multiple purchases
- **THEN** the system SHALL update each matched `BUY` transaction quantity and tax/non-tax bucket quantity
- **AND** the system SHALL set each corrected transaction's opening and closing quantity snapshots consistently with prior corrected receipt transactions

#### Scenario: Transaction match is missing or ambiguous
- **WHEN** a selected legacy receiving detail has zero or more than one matching original `BUY` transaction candidate
- **THEN** the system SHALL reject execution without creating a compensating transaction or partially normalizing the batch

### Requirement: Normalization preserves supplier financial facts and recalculates current HPP
The system SHALL preserve selected purchase headers, line monetary totals, taxes, discounts, payments, due amounts, supplier identity, and receipt locations while recalculating normalized per-base-unit cost and current product purchase-cost indicators.

#### Scenario: Supplier financial values remain unchanged
- **WHEN** a batch converts `10 BOX` with factor `12` into `120 PCS`
- **THEN** the system SHALL preserve the original supplier line and document monetary values
- **AND** the system SHALL use the preserved line cost divided by `120 PCS` as the normalized cost basis

#### Scenario: No downstream sale HPP is rewritten
- **WHEN** a batch executes successfully
- **THEN** the system SHALL update current average and last purchase-cost indicators from normalized receipt history
- **AND** the system SHALL not update sale cost snapshots

### Requirement: Normalization produces immutable audit evidence and project-native feedback
The system SHALL persist immutable before/after evidence for the batch and display the workflow through the existing Purchase module's standard cards, tables, action menus, confirmation UI, and inline or flash feedback patterns.

#### Scenario: Completed normalization is visible from affected purchases
- **WHEN** a batch completes
- **THEN** each affected purchase SHALL show a read-only normalization audit summary containing conversion, factor, base quantity, actor, time, reason, and batch reference

#### Scenario: Ineligible execution gives an actionable reason
- **WHEN** an authorized user attempts to execute an ineligible batch
- **THEN** the system SHALL show the specific eligibility or transaction-matching reason through standard application feedback
- **AND** the system SHALL not use a blocking browser dialog
