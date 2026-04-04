# Customer Creation Field Consistency

## Purpose
Ensure customer creation flows populate the same canonical name fields across admin and POS entry points.

## Requirements
### Requirement: POS customer name populates contact_name

When a customer is created from the POS sell page, the system SHALL store the provided customer name in both `customer_name` and `contact_name` fields so the customer appears correctly in the customer listing.

#### Scenario: POS-created customer appears in customer list
- **WHEN** a cashier creates a new customer "Toko ABC" from the POS sell page
- **THEN** the customer record SHALL have `contact_name` set to "Toko ABC"
- **AND** the customer record SHALL have `customer_name` set to "Toko ABC"
- **AND** the customer list at `/customers` SHALL display "Toko ABC" in the "Nama Pelanggan" column

#### Scenario: Livewire quick-add customer populates contact_name
- **WHEN** a user creates a new customer via the Livewire quick-add modal with name "PT Maju"
- **THEN** the customer record SHALL have `contact_name` set to "PT Maju"
