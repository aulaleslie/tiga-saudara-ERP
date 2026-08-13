## ADDED Requirements

### Requirement: Business POS walk-in customer selection is globally searchable
The Business Settings form SHALL provide a searchable selector for `pos_walk_in_customer_id` over the global customer list, and SHALL persist the selected global customer independently for each business setting.

#### Scenario: Administrator searches for the Cash customer
- **WHEN** an authorized administrator enters part of a customer name, contact name, or phone number in the Pelanggan Walk-In POS selector
- **THEN** the selector MUST present matching global customer records
- **AND** selecting and saving a result MUST store its customer ID on the active business setting

#### Scenario: Multiple businesses use the same global default
- **WHEN** administrators configure the same global customer as the walk-in customer for two different businesses
- **THEN** each business MUST retain that customer ID in its own `pos_walk_in_customer_id` setting
- **AND** the system MUST NOT duplicate the customer or reject it because of customer `setting_id` ownership

#### Scenario: Existing default remains selected
- **WHEN** the Business Settings form opens for a business with a valid `pos_walk_in_customer_id`
- **THEN** the searchable selector MUST display that configured customer as the current selection

### Requirement: POS cart exposes the configured walk-in customer as its default
When no customer has been explicitly selected, the POS cart SHALL resolve the active business's valid `pos_walk_in_customer_id` as the effective customer and SHALL identify the resolution source as `default` rather than `selected`.

#### Scenario: New cart displays configured default
- **WHEN** a cashier opens a new POS cart for a business with a valid walk-in customer configured
- **THEN** the cart snapshot MUST resolve that customer as the effective customer with `resolution_source=default`
- **AND** the POS customer area MUST visibly display the configured customer's canonical name

#### Scenario: Explicit selection overrides default
- **WHEN** a cashier selects another global customer through the existing POS customer search
- **THEN** that customer MUST become the effective customer with `resolution_source=selected`
- **AND** customer-dependent pricing MUST continue to use the explicitly selected customer's tier

#### Scenario: Clearing an explicit selection restores default
- **WHEN** a cashier clears an explicitly selected customer and the active business has a valid configured walk-in customer
- **THEN** the cart MUST return to the configured customer with `resolution_source=default`
- **AND** the UI MUST NOT show the cart as customer-unresolved

#### Scenario: Default is unavailable
- **WHEN** the active business has no valid walk-in customer configured and no customer is explicitly selected
- **THEN** the cart MUST remain unresolved with `resolution_source=none`
- **AND** existing checkout and save guards that require a resolved customer MUST remain enforced

### Requirement: Default customer resolution preserves split-checkout semantics
Default-customer presentation in the active POS cart MUST NOT convert the walk-in customer into an explicit cashier selection, and split posting SHALL continue resolving an absent explicit selection from each source business's configured global walk-in customer.

#### Scenario: Split checkout uses each source default
- **WHEN** a cart has no explicitly selected customer and checkout creates posting groups for multiple source businesses
- **THEN** each group MUST resolve its customer from that source business's `pos_walk_in_customer_id`
- **AND** valid global customer IDs MUST be accepted regardless of their customer `setting_id`

#### Scenario: Explicit global customer still crosses source businesses
- **WHEN** a cashier explicitly selects an existing global customer before a split checkout
- **THEN** existing global-customer split resolution MUST continue to use that selected customer for every eligible source group

