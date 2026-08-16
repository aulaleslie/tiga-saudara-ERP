## MODIFIED Requirements

### Requirement: Dispatch remains the authoritative fulfillment-stock gate for standard Sales
The system SHALL validate standard Sales fulfillment stock only in the dispatch workflow. Dispatch submission SHALL lock the Sale fulfillment boundary and validate authoritative remaining sale quantity before persisting pending demand. For inventory-managed items, submission SHALL validate selected location stock or selected serial availability and persist the server-resolved inventory-routing decision. Dispatch approval SHALL lock the pending dispatch, revalidate its routing identity and inventory, and deduct stock at most once before approving the dispatch.

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
- **WHEN** a user submits a dispatch quantity greater than the locked, unfulfilled quantity of a saved standard Sales parent or bundle component
- **THEN** the system SHALL reject the dispatch regardless of aggregate product stock availability
- **AND** the Sales order and prior dispatch records SHALL remain unchanged.

#### Scenario: Concurrent approval does not duplicate stock movement
- **WHEN** multiple requests attempt to approve the same pending inventory dispatch
- **THEN** only the request that locks the still-pending dispatch SHALL deduct stock and approve it
- **AND** later requests SHALL produce no inventory or serial side effects.
