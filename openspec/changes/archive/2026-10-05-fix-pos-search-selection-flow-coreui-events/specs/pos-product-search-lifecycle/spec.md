## MODIFIED Requirements

### Requirement: Simple product selection SHALL keep POS search results open
When a cashier selects an available `Cari Produk` result that requires neither sales-unit selection nor bundle selection, the POS SHALL submit one add-to-cart action without closing the product-search modal. The current keyword and rendered results SHALL remain available for another selection. Products requiring sales-unit or bundle selection SHALL continue through their existing selection dialogs, with the product-search modal closed and its search state retained for reopening. Once a sales-unit or bundle selection has completed, failed, or been cancelled, result cards SHALL accept selections again when product search is reopened, for every product in the results.

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

#### Scenario: Re-select a bundle-parent product after removing its line
- **WHEN** a cashier selects a bundle-parent result, chooses normal price or a bundle, removes the resulting cart line, reopens product search, and selects the same result again
- **THEN** the bundle selection flow opens again
- **AND** completing it adds the product to the cart

#### Scenario: Results stay selectable after a completed unit or bundle flow
- **WHEN** a sales-unit or bundle selection has completed and the cashier reopens product search
- **THEN** clicking any available result card starts its add or selection flow
- **AND** no click is silently ignored

#### Scenario: Dismissing the unit or bundle dialog cancels the selection
- **WHEN** a cashier closes the sales-unit or bundle selection dialog without choosing an option
- **THEN** the pending selection is cancelled and nothing is added to the cart
- **AND** reopened product search results accept selections again
- **AND** scanner input is not blocked by the cancelled selection
