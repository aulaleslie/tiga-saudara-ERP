## ADDED Requirements

### Requirement: Purchase product rows provide an authorized price-management entry point
The system SHALL provide the existing cross-business product price-management page as an additional navigation destination from selected purchase product rows, gated by the same dedicated price-management permission.

#### Scenario: Authorized purchase user navigates to price management
- **WHEN** a user with `products.manage_cross_business_prices` views a selected product in a purchase create or edit row
- **THEN** the product name SHALL navigate to that product's existing cross-business price-management page

#### Scenario: Unauthorized purchase user is not offered the entry point
- **WHEN** a user without `products.manage_cross_business_prices` views a selected product in a purchase create or edit row
- **THEN** the system SHALL not render a price-management navigation link

