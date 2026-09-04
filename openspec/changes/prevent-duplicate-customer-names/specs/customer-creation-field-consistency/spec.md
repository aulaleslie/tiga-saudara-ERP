## MODIFIED Requirements

### Requirement: POS customer creation stores contact_name as optional
When a customer is created from the POS sell page, the system SHALL store the provided customer name in `customer_name`. `contact_name` SHALL remain null unless explicitly provided, consistent with `contact_name` being optional supplemental contact-person information rather than the canonical customer identity.

#### Scenario: POS-created customer appears in customer list
- **WHEN** a cashier creates a new customer "Toko ABC" from the POS sell page
- **THEN** the customer record SHALL have `customer_name` set to "Toko ABC"
- **AND** the customer record SHALL have `contact_name` set to null
- **AND** the customer list at `/customers` SHALL display "Toko ABC" in the "Nama Pelanggan" column

#### Scenario: Livewire quick-add customer allows independent contact_name
- **WHEN** a user creates a new customer via a Livewire quick-add modal with `customer_name` "PT Maju" and no `contact_name` provided
- **THEN** the customer record SHALL have `customer_name` set to "PT Maju"
- **AND** the customer record SHALL have `contact_name` set to null
