# Spec Delta

## ADDED Requirements

### Requirement: Simple product selection SHALL keep POS search results open
When a cashier selects an available `Cari Produk` result that requires neither sales-unit selection nor bundle selection, the POS SHALL submit one add-to-cart action without closing the product-search modal. The current keyword and rendered results SHALL remain available for another selection. Products requiring sales-unit or bundle selection SHALL continue through their existing selection dialogs, with the product-search modal closed and its search state retained for reopening.

#### Scenario: Select a simple product repeatedly
- **WHEN** a cashier clicks the same available simple-product result multiple times during one transaction
- **THEN** the product-search modal remains open after each selection
- **AND** each successful selection adds one unit to the matching cart line
- **AND** the keyword and results remain visible

#### Scenario: Select different simple products from one result set
- **WHEN** a cashier clicks different available simple-product results from the same search
- **THEN** the modal remains open after each selection
- **AND** each successful selection adds or updates the appropriate cart line

#### Scenario: Product requires sales-unit selection
- **WHEN** a cashier selects a result that requires a sales-unit choice
- **THEN** the product-search modal closes and the existing sales-unit selection flow opens
- **AND** the keyword and results remain available when product search is reopened in the same transaction

#### Scenario: Product requires bundle selection
- **WHEN** a cashier selects a result that requires bundle selection
- **THEN** the product-search modal closes and the existing bundle selection flow opens
- **AND** the keyword and results remain available when product search is reopened in the same transaction

#### Scenario: Simple-product add fails
- **WHEN** an add-to-cart request for a simple-product result fails
- **THEN** the product-search modal remains open with its keyword and results intact
- **AND** the cart does not show a successful addition for that request

