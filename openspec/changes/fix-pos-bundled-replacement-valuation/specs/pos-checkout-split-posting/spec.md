## ADDED Requirements

### Requirement: Split Bundled Parent Unit Price SHALL Exclude Component Allocation
When POS split posting finalizes a selected bundled parent line, the system SHALL persist the parent `sale_details.price` and `sale_details.unit_price` from that owner group's parent residual commercial amount only. Bundle component allocation value MAY contribute to the owner group's `sale_details.sub_total`, `sales.total_amount`, `pos_checkout_sales.grand_total`, and payment allocation, but it MUST remain represented as component value through `sale_bundle_items` and MUST NOT inflate the parent product unit price.

#### Scenario: Component-owning group keeps parent residual unit price
- **WHEN** a POS checkout finalizes a bundled parent line with two serialized parent units priced at 6,100,000 each
- **AND** the bundle has one component priced at 15,000 per bundle
- **AND** the first owner group owns one parent serial and no component allocation
- **AND** the second owner group owns one parent serial and both component allocations
- **THEN** the first owner group's parent sale detail `price` and `unit_price` are 6,085,000
- **AND** the second owner group's parent sale detail `price` and `unit_price` are 6,085,000
- **AND** the second owner group's sale detail `sub_total`, sale total, checkout sale grand total, and payment allocation are 6,115,000
- **AND** the second owner group's `sale_bundle_items` rows represent the 30,000 component allocation.

#### Scenario: Split bundled totals still reconcile
- **WHEN** POS split posting persists bundled parent sale detail unit prices from parent residual amounts
- **THEN** the sum of generated split group grand totals still equals the POS checkout grand total
- **AND** the sum of parent residual value and component allocation value still equals the customer-facing bundled row gross amount.
