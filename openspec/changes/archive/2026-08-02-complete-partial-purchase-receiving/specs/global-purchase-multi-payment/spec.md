## ADDED Requirements

### Requirement: Completed supplier-shortfall purchases are payable
The global purchase-payment workflow SHALL treat a non-archived purchase completed through the authorized supplier-shortfall completion workflow as eligible under its existing exact-`RECEIVED` lifecycle rule, using the purchase's normalized current live outstanding balance.

#### Scenario: Normalized partial purchase appears for payment
- **WHEN** an authorized user completes an eligible partially received purchase as a supplier shortfall
- **AND** the normalized purchase has an exact status of `RECEIVED` and a positive live outstanding balance
- **THEN** the global purchase-payment list and supplier allocation candidates SHALL include the purchase according to their existing supplier, permission, and filter rules
