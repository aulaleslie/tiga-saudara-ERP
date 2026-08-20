## MODIFIED Requirements

### Requirement: Sales dispatch SHALL acknowledge non-stock and inventory fulfillment separately
The Sales dispatch page and server-side dispatch processing SHALL include stock-managed and non-stock parent products and bundle components as fulfillment acknowledgement demand. Stock-managed demand SHALL require applicable location, stock, and serial validation and SHALL create inventory effects only after approval. Non-stock demand SHALL create auditable dispatch details and contribute to fulfillment completion without requiring stock or serial allocation and without changing product stock, location stock, serial state, or inventory transactions.

#### Scenario: Service-only Sale records completed work
- **WHEN** a user submits and approves quantity two for a Sale containing two non-stock laptop service units
- **THEN** the system SHALL persist approved dispatch acknowledgement quantity two
- **AND** it SHALL create no stock, serial, or inventory-transaction effect

#### Scenario: Non-stock bundle parent retains stock-managed component fulfillment
- **WHEN** a Sale contains a non-stock bundle parent with a stock-managed component
- **THEN** the dispatch flow SHALL acknowledge both the non-stock parent and stock-managed component quantities
- **AND** only the stock-managed component SHALL require inventory validation and movement

#### Scenario: Stockless bundle component is audit-only
- **WHEN** an approved bundle dispatch contains a non-stock component
- **THEN** its dispatch detail SHALL remain fulfillment evidence
- **AND** it SHALL create no inventory movement

### Requirement: Dispatch status SHALL use approved fulfillment acknowledgements
Sales dispatch status SHALL compare approved dispatched quantity against parent and bundle-component acknowledgement demand for both stock-managed and non-stock content. A service-only Sale SHALL become fully dispatched when all completed-work acknowledgements are approved, and a mixed Sale SHALL become fully dispatched only when all goods and service acknowledgement demand is approved.

#### Scenario: Mixed Sale waits for goods and service acknowledgement
- **WHEN** a Sale contains one non-stock service and one stock-managed product
- **AND** only one of those demands has been fully approved
- **THEN** the Sale SHALL remain partially dispatched

#### Scenario: Mixed Sale completes after all acknowledgements
- **WHEN** all stock-managed and non-stock demand for a mixed Sale has approved dispatch quantities
- **THEN** the Sale SHALL be fully dispatched

#### Scenario: Service-only Sale completes from approved work
- **WHEN** all quantities on a service-only Sale have approved non-stock dispatch acknowledgements
- **THEN** the Sale SHALL be fully dispatched without any inventory movement

#### Scenario: Pending or rejected work does not complete the Sale
- **WHEN** non-stock dispatch acknowledgement demand is pending or rejected
- **THEN** that quantity SHALL not count as approved Sale completion

