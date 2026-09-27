# pos-cart-default-walkin-customer Specification

## Purpose
Defines lazy fallback resolution of the configured `Setting.pos_walk_in_customer_id` for single-terminal POS carts, so cashiers are not required to manually select the walk-in customer on every transaction. Resolution applies consistently across cart display, draft saving, and checkout finalization, while requiring explicit customer selection for debt checkout.

## Requirements

### Requirement: Default walk-in customer resolved when no customer is explicitly selected
Whenever the customer resolution service resolves customer information for a POS cart under a setting with `pos_walk_in_customer_id` configured and `selected_customer_id` is null, the system SHALL resolve the walk-in customer with `resolution_source: 'walk_in'`.

#### Scenario: New transaction on page load with configured default
- **WHEN** a cashier opens the POS Sell page for a setting with `pos_walk_in_customer_id` configured
- **AND** no customer is explicitly selected
- **THEN** the cart snapshot SHALL have `customer.resolution_source` equal to `'walk_in'` and `customer.resolved_customer_id` equal to the configured walk-in customer

#### Scenario: Clear cart preserves walk-in fallback
- **WHEN** a cashier triggers "Kosongkan Keranjang" (clear cart) on a setting with `pos_walk_in_customer_id` configured
- **THEN** the resulting empty cart SHALL resolve with `resolution_source: 'walk_in'` and `resolved_customer_id` equal to the configured walk-in customer

#### Scenario: Fresh cart after successful checkout preserves walk-in fallback
- **WHEN** a cashier completes a successful checkout on a setting with `pos_walk_in_customer_id` configured
- **AND** the session cart is wiped and rebuilt
- **THEN** the rebuilt cart SHALL resolve with `resolution_source: 'walk_in'` and `resolved_customer_id` equal to the configured walk-in customer

#### Scenario: No configured default leaves cart unresolved
- **WHEN** a fresh cart is constructed for a setting where `pos_walk_in_customer_id` is null
- **THEN** the cart customer resolution SHALL have `resolution_source: 'none'` and `resolved_customer_id: null`

### Requirement: Explicit selection overrides default walk-in customer
When a cashier explicitly selects a customer, the explicit selection SHALL take precedence with `resolution_source: 'selected'`.

#### Scenario: Cashier selects a customer
- **WHEN** a cashier selects a customer through the customer selection UI
- **THEN** the cart's customer resolution SHALL update to `resolution_source: 'selected'`, `selected_customer_id` equal to the chosen customer, and tier repricing applies normally

### Requirement: Debt checkout requires explicit customer selection
Checkout as debt (kas-bon / piutang) SHALL strictly require an explicit customer selection (`resolution_source: 'selected'`) and SHALL reject with `CUSTOMER_REQUIRED` if the customer is only resolved via walk-in fallback (`resolution_source: 'walk_in'`).

#### Scenario: Debt checkout with walk-in default is rejected
- **WHEN** a cashier attempts to checkout as debt (`is_debt: true`) on a setting with `pos_walk_in_customer_id` configured
- **AND** no explicit customer was selected (`resolution_source: 'walk_in'`)
- **THEN** checkout finalization SHALL be rejected with `CUSTOMER_REQUIRED`

#### Scenario: Debt checkout with explicitly selected customer succeeds
- **WHEN** a cashier attempts to checkout as debt with an explicitly selected customer (`resolution_source: 'selected'`)
- **THEN** checkout finalization proceeds normally subject to approval and credit terms
