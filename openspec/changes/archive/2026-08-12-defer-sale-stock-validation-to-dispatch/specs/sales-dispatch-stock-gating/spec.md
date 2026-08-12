## ADDED Requirements

### Requirement: Standard Sales documents record demand independently of current stock
The system SHALL allow authorized users to create a standard Sales document containing stock-managed products or bundle components when their current aggregate product quantity is zero or less than the ordered quantity. The system SHALL apply the same rule when updating an editable standard Sales document. Saving the document SHALL NOT reserve or deduct inventory.

#### Scenario: Zero-stock product is saved on a standard sale
- **WHEN** an authorized user creates a standard Sales document for a stock-managed product whose current aggregate quantity is zero
- **THEN** the system SHALL persist the Sales header and line successfully
- **AND** the system SHALL not change product, location-stock, or inventory-transaction quantities.

#### Scenario: Insufficient bundle component does not block a standard sale
- **WHEN** an authorized user creates or updates an editable standard Sales document whose bundle component demand exceeds its current aggregate quantity
- **THEN** the system SHALL persist the Sales document and its bundle-line context successfully
- **AND** the system SHALL defer inventory availability validation until dispatch.

### Requirement: Dispatch remains the authoritative fulfillment-stock gate for standard Sales
The system SHALL validate standard Sales fulfillment stock only in the dispatch workflow. Dispatch submission SHALL validate the authoritative remaining sale quantity and, for inventory-managed items, the selected location stock or selected serial availability. Dispatch approval SHALL lock and revalidate inventory before deducting stock and approving the dispatch.

#### Scenario: Dispatch submission rejects unavailable selected-location stock
- **WHEN** a user submits a dispatch for a saved standard Sales order and the requested inventory-managed quantity exceeds stock at the selected allowed location
- **THEN** the system SHALL reject the dispatch submission with a stock insufficiency error
- **AND** the system SHALL not create a pending dispatch or deduct inventory.

#### Scenario: Stock changes before pending dispatch approval
- **WHEN** a pending dispatch passed submission validation
- **AND** the selected location no longer has sufficient stock when approval begins
- **THEN** the system SHALL reject approval and leave the dispatch pending
- **AND** the system SHALL not deduct inventory or mark serials as sold.

#### Scenario: Dispatch continues to enforce sale fulfillment bounds
- **WHEN** a user submits a dispatch quantity greater than the unfulfilled quantity of a saved standard Sales line or bundle component
- **THEN** the system SHALL reject the dispatch regardless of aggregate product stock availability
- **AND** the Sales order and prior dispatch records SHALL remain unchanged.

### Requirement: POS checkout stock policy remains unchanged
The system SHALL NOT relax POS checkout stock or serial validation as part of the standard Sales stock-gating policy.

#### Scenario: POS checkout with unavailable stock
- **WHEN** a POS checkout attempts to finalize an inventory-managed line that cannot be fulfilled by its existing stock-validation policy
- **THEN** the POS checkout SHALL continue to reject finalization according to its existing requirements.
