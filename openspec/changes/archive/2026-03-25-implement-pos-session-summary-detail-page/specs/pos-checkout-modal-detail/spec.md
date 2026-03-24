## ADDED Requirements

### Requirement: Display checkout detail modal
When a user clicks a transaction in the session detail page, a modal SHALL open showing the complete details of that POS checkout record. The modal SHALL display receipt number, customer (if present), payment method, grand total, items/lines, and timestamps.

#### Scenario: Open modal with valid checkout
- **WHEN** user clicks a transaction row in the ledger
- **THEN** system fetches checkout details and opens a modal popup
- **AND** the modal displays receipt number, amounts (subtotal, discount, tax, grand total), and payment method name

#### Scenario: Modal displays item details
- **WHEN** modal is open
- **THEN** if the checkout has multiple payment stages, all stages are visible
- **AND** the modal shows finalized timestamp in local format

#### Scenario: Close checkout detail modal
- **WHEN** user clicks the close button or outside the modal
- **THEN** the modal closes without reloading the page

#### Scenario: Modal displays customer (if present)
- **WHEN** the checkout has an associated customer
- **THEN** customer name is shown in the modal
- **AND** if no customer, "Walk-in" or "-" is displayed
