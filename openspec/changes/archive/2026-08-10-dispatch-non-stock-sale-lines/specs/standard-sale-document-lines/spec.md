## MODIFIED Requirements

### Requirement: Dispatch aggregates preserved document rows by fulfilment key
The system SHALL keep Dispatch demand aggregation based on the sale parent and product/tax/bundle fulfilment keys, regardless of how many document rows exist. Stock-managed parent products and stock-managed bundle components SHALL expose normal inventory Dispatch controls. Non-stock-managed parent products and non-stock-managed bundle components SHALL expose the same ordered, approved, and requested quantity flow as completion acknowledgements without inventory controls. Each parent row and bundle component SHALL remain an independent fulfilment obligation.

#### Scenario: Dispatch view aggregates duplicate stock-managed sale details
- **WHEN** a standard sale has multiple stock-managed `sale_details` rows with the same product, tax, and bundle state
- **AND** a user opens the dispatch page
- **THEN** the dispatch product table SHALL show aggregate dispatchable quantity for that product/tax/bundle
- **AND** the aggregate quantity SHALL equal the sum of the matching saved sale detail quantities

#### Scenario: Dispatch validation uses aggregate remaining inventory quantity
- **WHEN** a standard sale has duplicate saved stock-managed details for the same product, tax, and bundle state
- **AND** a user submits a dispatch quantity for that product/tax/bundle
- **THEN** validation SHALL compare the submitted quantity against the aggregate remaining inventory quantity for the sale parent
- **AND** validation SHALL NOT require a specific `sale_details` row to be selected

#### Scenario: Non-stock document rows create completion acknowledgement demand
- **WHEN** a standard sale contains non-stock-managed detail rows or non-stock-managed bundle components
- **THEN** Dispatch aggregation SHALL include those rows with their remaining acknowledgement quantity
- **AND** they SHALL not require a location, serial, or available-stock value
- **AND** their approved quantities SHALL affect Dispatch completion without inventory effects

#### Scenario: Non-stock parent and stock-managed component are independently fulfilled
- **WHEN** a Sale contains a non-stock laptop-service bundle parent with ordered quantity two
- **AND** it contains a stock-managed RAM replacement component with quantity one per bundle
- **THEN** Dispatch demand SHALL include a service acknowledgement quantity of two and an inventory RAM quantity of two
- **AND** approving only the RAM quantity SHALL leave the Sale `DISPATCHED PARTIALLY`
- **AND** approving both quantities SHALL make the Sale `DISPATCHED`
