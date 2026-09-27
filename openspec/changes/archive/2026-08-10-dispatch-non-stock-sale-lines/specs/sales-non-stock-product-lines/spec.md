## MODIFIED Requirements

### Requirement: Sales dispatch SHALL acknowledge non-stock products without inventory fulfilment
The Sales dispatch page and server-side Dispatch processing SHALL include non-stock parent products and non-stock bundle components as quantity-based completion/delivery acknowledgements. A non-stock acknowledgement SHALL create a Dispatch detail through the existing pending/approved/rejected approval workflow, but SHALL NOT require a location or serial data and SHALL NOT create product-stock mutation, product quantity mutation, serial handling or history, or inventory transactions. Stock-managed parent products and stock-managed bundle components SHALL retain normal location, serial, stock validation, Dispatch-detail, and inventory behavior.

#### Scenario: Service-only Sale is partially and fully acknowledged
- **WHEN** a user opens Dispatch for a Sale containing only a non-stock service line with ordered quantity greater than one
- **THEN** the page SHALL show that line with its ordered, previously approved, and dispatch quantity values
- **AND** the user SHALL be able to submit and approve a partial quantity without a location or serial data
- **AND** the Sale SHALL be `DISPATCHED PARTIALLY` until the full ordered service quantity is approved
- **AND** the Sale SHALL be `DISPATCHED` once the full ordered service quantity is approved

#### Scenario: Non-stock acknowledgement has no inventory side effects
- **WHEN** a pending Dispatch containing a non-stock line is approved
- **THEN** the system SHALL retain the approved Dispatch and its quantity detail as the completion acknowledgement
- **AND** SHALL NOT create a product-stock mutation, product quantity mutation, serial assignment or serial-history entry, or inventory transaction for that line

#### Scenario: Mixed Sale retains stock validation and service acknowledgement
- **WHEN** a Sale contains a non-stock service and a stock-managed product
- **THEN** Dispatch SHALL allow a quantity acknowledgement for the service without inventory inputs
- **AND** SHALL require and validate normal location, stock, and serial inputs only for the stock-managed product
- **AND** approving the Dispatch SHALL deduct and record inventory only for the stock-managed product

#### Scenario: Rejected service acknowledgement does not fulfil the Sale
- **WHEN** a pending Dispatch containing a non-stock acknowledgement is rejected
- **THEN** its quantity SHALL NOT count toward the Sale's approved dispatch progress
- **AND** the existing rejection reason, notification, and audit behavior SHALL be retained

### Requirement: Dispatch status SHALL use all Dispatch fulfilment obligations
Sales dispatch status SHALL compare approved Dispatch quantities against every parent product and bundle-component fulfilment obligation, regardless of stock management. Non-stock quantities SHALL count only after their Dispatch is approved. A Sale SHALL be `DISPATCHED PARTIALLY` while any required parent or component quantity remains unapproved and SHALL be `DISPATCHED` only after all such quantities are approved.

#### Scenario: Mixed Sale stays partial until its service is acknowledged
- **WHEN** a Sale contains one non-stock service and one stock-managed product
- **AND** the full stock-managed product quantity is approved for Dispatch
- **AND** some or all of the service quantity remains unapproved
- **THEN** the Sale SHALL be `DISPATCHED PARTIALLY`

#### Scenario: Service-only Sale completes after approval
- **WHEN** a Sale contains only non-stock service lines
- **AND** every ordered service quantity is represented by approved Dispatch details
- **THEN** the Sale SHALL be `DISPATCHED`
