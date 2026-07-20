## ADDED Requirements

### Requirement: Form state initialization on modal open
The staged checkout modal SHALL completely reset its form state (including payment method, amount input, and EDC reference) every time it is opened for a new transaction, ensuring no data leaks from previous transactions.

#### Scenario: Opening modal for a new transaction
- **WHEN** the user opens the staged checkout modal for a new transaction
- **THEN** the payment method search input is blank
- **AND** the amount input is blank and its raw value is reset
- **AND** the EDC reference input is blank
- **AND** any validation errors from previous transactions are cleared
