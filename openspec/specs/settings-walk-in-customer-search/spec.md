# settings-walk-in-customer-search Specification

## Purpose
TBD - created by archiving change add-pos-walkin-customer-searchable-dropdown. Update Purpose after archive.
## Requirements
### Requirement: Searchable walk-in customer selection
The Business Settings page SHALL present the "Pelanggan Walk-In POS" field as a searchable dropdown that queries customers on demand, instead of preloading every customer record into the page.

#### Scenario: Page loads without preloading all customers
- **WHEN** an authorized user opens the Business Settings page
- **THEN** the system SHALL NOT query or render the full customer list in the page response
- **AND** if `pos_walk_in_customer_id` is currently set, the system SHALL fetch only that single customer to pre-render it as the selected option

#### Scenario: Admin searches for a walk-in customer
- **WHEN** an authorized user types a search term into the "Pelanggan Walk-In POS" field
- **THEN** the system SHALL query matching customers by name, contact name, or phone number and display up to the configured result limit

#### Scenario: Admin clears the walk-in customer selection
- **WHEN** an authorized user selects the "Belum diatur" (not set) option
- **THEN** the system SHALL save `pos_walk_in_customer_id` as null on submission, matching current behavior

### Requirement: Settings-scoped customer search endpoint
The system SHALL expose a customer search endpoint for the Business Settings page that is reachable independently of POS enablement or POS-specific permissions.

#### Scenario: Search works when POS is disabled for the business
- **WHEN** a user with `settings.access` permission searches the walk-in customer field on a business where `pos_enabled` is false
- **THEN** the search request SHALL succeed and return matching customers

#### Scenario: Search works without POS permissions
- **WHEN** a user has `settings.access` but lacks `pos.access` / `pos.returns.view` permissions
- **THEN** the user SHALL still be able to search and select a walk-in customer on the Settings page

#### Scenario: Search respects Settings page authorization
- **WHEN** a user without `settings.access` attempts to call the settings customer search endpoint directly
- **THEN** the system SHALL deny the request, consistent with the rest of the Settings page's authorization

