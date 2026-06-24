## ADDED Requirements

### Requirement: Terminal Harga SHALL display non-tier prices by default
Terminal Harga SHALL display the active setting's normal product sale price when no customer is selected.

#### Scenario: Product card before customer selection
- **WHEN** a user opens Terminal Harga with an active setting
- **AND** a listed product has a `product_prices` row for that active setting
- **AND** no customer is selected
- **THEN** the product card SHALL display the active-setting `sale_price`
- **AND** the product card SHALL NOT display `tier_1_price` or `tier_2_price` as additional price rows

#### Scenario: Product search keeps default price behavior
- **WHEN** a user searches or scans a product keyword in Terminal Harga
- **AND** no customer is selected
- **THEN** matching product cards SHALL display active-setting `sale_price`

### Requirement: Terminal Harga SHALL provide global customer selection
Terminal Harga SHALL provide a searchable customer control that searches customers globally and ignores `customers.setting_id` for both search results and selected-customer tier resolution.

#### Scenario: Customer from different setting is searchable
- **WHEN** a customer exists with a `setting_id` value different from the active Terminal Harga setting
- **AND** the user searches by that customer's name or contact name
- **THEN** Terminal Harga SHALL include that customer in the searchable results

#### Scenario: Selected customer ignores customer setting id
- **WHEN** a user selects a customer whose `setting_id` differs from the active Terminal Harga setting
- **THEN** Terminal Harga SHALL accept the selection
- **AND** Terminal Harga SHALL use that customer's `tier` value for price display
- **AND** Terminal Harga SHALL continue reading product prices from the active setting's `product_prices` rows

### Requirement: Terminal Harga SHALL display customer-tier prices after selection
When a customer is selected, Terminal Harga SHALL display exactly one contextual price per product according to the selected customer's tier and the active setting's product price row.

#### Scenario: Wholesaler customer displays tier 1 price
- **WHEN** a user selects a customer with tier `WHOLESALER`
- **AND** a listed product has a positive active-setting `tier_1_price`
- **THEN** the product card SHALL display that `tier_1_price`
- **AND** the product card SHALL NOT display the normal, tier 1, and tier 2 prices as separate rows

#### Scenario: Reseller customer displays tier 2 price
- **WHEN** a user selects a customer with tier `RESELLER`
- **AND** a listed product has a positive active-setting `tier_2_price`
- **THEN** the product card SHALL display that `tier_2_price`
- **AND** the product card SHALL NOT display the normal, tier 1, and tier 2 prices as separate rows

#### Scenario: Missing wholesaler price falls back to normal price
- **WHEN** a user selects a customer with tier `WHOLESALER`
- **AND** a listed product has no positive active-setting `tier_1_price`
- **THEN** the product card SHALL display the active-setting `sale_price`

#### Scenario: Missing reseller price falls back to normal price
- **WHEN** a user selects a customer with tier `RESELLER`
- **AND** a listed product has no positive active-setting `tier_2_price`
- **THEN** the product card SHALL display the active-setting `sale_price`

#### Scenario: Customer without recognized tier displays normal price
- **WHEN** a user selects a customer with no tier or an unrecognized tier
- **THEN** listed product cards SHALL display active-setting `sale_price`

### Requirement: Terminal Harga SHALL allow clearing selected customer
Terminal Harga SHALL allow users to clear the selected customer and return product cards to default non-tier pricing.

#### Scenario: Clear customer selection
- **WHEN** a user has selected a customer with tier pricing in Terminal Harga
- **AND** the user clears the selected customer
- **THEN** Terminal Harga SHALL remove the selected customer context
- **AND** listed product cards SHALL display active-setting `sale_price`

### Requirement: Terminal Harga SHALL preserve existing browsing behavior
Terminal Harga SHALL preserve existing product search, scanner submit, pagination, active-setting product price filtering, currency formatting, product metadata display, and conversion display behavior while adding customer-tier price display.

#### Scenario: Existing product price row eligibility is preserved
- **WHEN** Terminal Harga renders product results
- **THEN** products SHALL still be listed only when they have a `product_prices` row for the active setting

#### Scenario: Product search refocus remains scanner friendly
- **WHEN** the user submits a product search in Terminal Harga
- **THEN** Terminal Harga SHALL continue refocusing the product search input after the search is processed

#### Scenario: Customer change resets product pagination
- **WHEN** the user selects or clears a customer while viewing a later product result page
- **THEN** Terminal Harga SHALL reset product pagination to the first page
