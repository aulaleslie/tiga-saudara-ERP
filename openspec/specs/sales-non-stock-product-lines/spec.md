## Purpose

This spec governs how non-stock (non-inventory) products are sold through the standard Sales workflow, including product search, line persistence, inventory validation boundaries, dispatch exclusion, and dispatch status calculation.

## Requirements

### Requirement: Standard Sales SHALL select saleable non-stock products
The standard Sales create and edit product search SHALL return active products with `is_sold = true` regardless of `stock_managed`. A selected non-stock product SHALL be added as a standard Sales cart row and retain the normal Sales pricing, discount, tax, quantity, customer, and line-total behavior.

#### Scenario: User selects a repair service
- **WHEN** a user searches the standard Sales product picker for an active repair-service product with `is_sold = true` and `stock_managed = false`
- **THEN** the product SHALL be returned and selectable
- **AND** selecting it SHALL add a normal Sales cart row

#### Scenario: Non-saleable product remains unavailable
- **WHEN** a user searches for an active product with `is_sold = false`
- **THEN** the standard Sales product picker SHALL NOT return that product

### Requirement: Non-stock Sales lines SHALL persist as financial document lines
The system SHALL create and update non-stock Sales lines through the same document persistence, pricing, discount, tax, payment, invoice, reporting, and cost-snapshot paths as other standard Sales lines. A non-stock Sales line SHALL retain the existing zero-cost snapshot behavior.

#### Scenario: Non-stock service sale is saved
- **WHEN** a user creates or updates a Sale containing a non-stock line
- **THEN** the line and its financial totals SHALL persist on the Sale
- **AND** the line SHALL receive a zero-cost non-stock cost snapshot

### Requirement: Inventory validation SHALL exclude non-stock Sales demand
The system SHALL apply stock-availability validation only to stock-managed parent products and stock-managed bundle components. A non-stock parent product SHALL not require global or location stock to create or update a Sale.

#### Scenario: Service-only Sale saves with no inventory
- **WHEN** a user creates a Sale containing only non-stock products with zero product and location stock
- **THEN** the Sale SHALL save successfully
- **AND** no stock-availability validation error SHALL be raised for those lines

#### Scenario: Mixed Sale still protects stock-managed goods
- **WHEN** a user creates or updates a Sale containing a non-stock service and a stock-managed product whose requested quantity exceeds available stock
- **THEN** the operation SHALL be rejected for the stock-managed product
- **AND** the non-stock service SHALL not contribute to the stock-validation error

### Requirement: Sales dispatch SHALL exclude non-stock products from inventory fulfillment
The Sales dispatch page and server-side dispatch processing SHALL exclude non-stock parent products and non-stock bundle components from dispatch demand, location selection, serial validation, dispatch details, stock decrements, and inventory transactions. Stock-managed components of a selected bundle SHALL remain subject to normal dispatch behavior even when the parent is non-stock-managed.

#### Scenario: Service-only Sale has no dispatch demand
- **WHEN** a user opens dispatch for a Sale containing only non-stock service lines
- **THEN** the dispatch page SHALL show no inventory demand for those lines
- **AND** no dispatch detail or inventory transaction SHALL be created for them

#### Scenario: Non-stock bundle parent retains stock-managed component dispatch
- **WHEN** a Sale contains a non-stock bundle parent with a stock-managed component
- **THEN** the dispatch flow SHALL include and validate the stock-managed component
- **AND** it SHALL exclude the non-stock parent and any non-stock component from inventory fulfillment

### Requirement: Dispatch status SHALL use inventory-fulfilled demand only
Sales dispatch status SHALL compare approved dispatched quantity against stock-managed parent and bundle-component demand only. A service-only Sale with no stock-managed demand SHALL remain `APPROVED`; a mixed Sale SHALL become fully dispatched when all of its stock-managed demand is approved for dispatch.

#### Scenario: Mixed Sale completes after physical goods dispatch
- **WHEN** a Sale contains one non-stock service and one stock-managed product
- **AND** the full stock-managed product quantity is approved for dispatch
- **THEN** the Sale SHALL be `DISPATCHED`

#### Scenario: Service-only Sale remains commercially approved
- **WHEN** a Sale contains only non-stock service lines
- **THEN** it SHALL remain `APPROVED` without a dispatch
