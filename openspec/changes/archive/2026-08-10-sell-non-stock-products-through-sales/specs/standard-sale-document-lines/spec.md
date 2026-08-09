## MODIFIED Requirements

### Requirement: Dispatch aggregates preserved inventory-fulfilled document rows by fulfillment key
The system SHALL keep dispatch demand aggregation based on the sale parent and product/tax/bundle fulfillment keys for stock-managed parent products and stock-managed bundle components, regardless of how many document rows exist. Non-stock-managed parent products and non-stock-managed bundle components SHALL not contribute dispatch demand.

#### Scenario: Dispatch view aggregates duplicate stock-managed sale details
- **WHEN** a standard sale has multiple stock-managed `sale_details` rows with the same product, tax, and bundle state
- **AND** a user opens the dispatch page
- **THEN** the dispatch product table SHALL show aggregate dispatchable quantity for that product/tax/bundle
- **AND** the aggregate quantity SHALL equal the sum of the matching saved stock-managed sale detail quantities

#### Scenario: Dispatch validation uses aggregate remaining inventory quantity
- **WHEN** a standard sale has duplicate saved stock-managed details for the same product, tax, and bundle state
- **AND** a user submits a dispatch quantity for that product/tax/bundle
- **THEN** validation SHALL compare the submitted quantity against the aggregate remaining inventory quantity for the sale parent
- **AND** validation SHALL NOT require a specific `sale_details` row to be selected

#### Scenario: Non-stock document rows do not create dispatch demand
- **WHEN** a standard sale contains non-stock-managed detail rows or non-stock-managed bundle components
- **THEN** dispatch aggregation SHALL exclude those rows and components
- **AND** they SHALL not affect remaining dispatch quantity or dispatch completion
