## ADDED Requirements

### Requirement: Robust Customer Identity Display on Reprint
Reprint receipts SHALL use the same robust customer identity display logic as the main POS receipt. It MUST safely ignore empty strings (`""`) and combine `contact_name` and `company_name` (or `customer_name`) appropriately to provide maximum context, ensuring a blank name is never printed due to an empty string in the primary fallback field.

#### Scenario: Customer name is fully reconstructed on reprint
- **WHEN** a user reprints a transaction receipt
- **THEN** the system MUST evaluate the customer's `contact_name`, `company_name`, and `customer_name` using empty-string-safe logic
- **AND** it MUST format the output as "Contact Name - Company Name" if both are present and distinct
