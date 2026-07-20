## ADDED Requirements

### Requirement: Canonical customer identity reads
The system SHALL treat `customers.customer_name` as the canonical customer identity in customer lists, ordinary customer labels, search results, selectors, and loaders. It MUST NOT render a customer as blank solely because `contact_name` is null or empty.

#### Scenario: Import-shaped customer appears in the customer list
- **WHEN** an existing customer has a populated `customer_name` and a null or empty `contact_name`
- **THEN** the customer list SHALL display `customer_name` in the "Nama Pelanggan" column

#### Scenario: Import-shaped customer appears in selectors
- **WHEN** a user searches for or loads a customer whose `customer_name` is populated and `contact_name` is null or empty
- **THEN** every ordinary customer selector or loader SHALL return a nonblank label based on `customer_name`

#### Scenario: Historical blank canonical name has safe fallback
- **WHEN** a historical customer has an empty `customer_name` and a populated `contact_name`
- **THEN** presentation code SHALL use `contact_name` as a compatibility fallback instead of rendering a blank label

### Requirement: Supplemental contact information remains distinct
The system SHALL treat `contact_name` as optional supplemental contact-person information and MUST NOT treat it as the canonical customer identity. Display resolution MUST ignore null, empty, and whitespace-only values and MUST NOT repeat an identical `customer_name` and `contact_name` as `NAME - NAME`.

#### Scenario: Canonical and contact names are identical
- **WHEN** an existing customer has equal non-empty `customer_name` and `contact_name` values
- **THEN** any combined display label SHALL include that value only once

#### Scenario: Distinct contact remains available
- **WHEN** an existing customer has different non-empty `customer_name` and `contact_name` values
- **THEN** screens that explicitly present contact-person context MAY display both values
- **AND** customer identity matching and ordinary customer labels SHALL remain based on `customer_name`

### Requirement: Customer search remains backward compatible
Customer search SHALL match `customer_name` and MAY additionally match `contact_name` and phone fields used by the existing surface. Search ordering and returned identity labels SHALL prioritize `customer_name`.

#### Scenario: Search by canonical name
- **WHEN** a user searches using text contained in `customer_name`
- **THEN** the matching customer SHALL be returned regardless of `contact_name` or `setting_id`

#### Scenario: Search by historical contact name
- **WHEN** a user searches using text contained only in a populated `contact_name`
- **THEN** the matching customer SHALL remain discoverable
- **AND** its ordinary result label SHALL identify it using `customer_name` when populated

### Requirement: Customer visibility and selection are global
The system SHALL load, search, validate, and select customers by global customer-record existence and MUST NOT require `customers.setting_id` to match the active, terminal, sale-owner, or source setting. A stored `setting_id` MAY remain as provenance and MUST NOT change transaction ownership.

#### Scenario: Customer from another setting is selectable
- **WHEN** an existing customer has a `setting_id` different from the active setting
- **THEN** authorized Customer, Sales, POS, and customer-loader surfaces SHALL permit that customer to be found and selected

#### Scenario: Customer without a setting is selectable
- **WHEN** an existing customer has a null `setting_id`
- **THEN** authorized Customer, Sales, POS, and customer-loader surfaces SHALL permit that customer to be found and selected

#### Scenario: Global customer does not change transaction ownership
- **WHEN** a customer from another or no setting is selected for a setting-owned transaction
- **THEN** the transaction SHALL retain its independently resolved setting ownership

### Requirement: POS walk-in customer configuration uses global identity
The Settings POS walk-in customer options and validation SHALL accept any existing global customer record and MUST NOT restrict candidates or validity by `customers.setting_id`.

#### Scenario: Configure cross-setting walk-in customer
- **WHEN** an authorized user selects an existing walk-in customer whose `setting_id` differs from the active setting
- **THEN** Settings SHALL accept and persist that customer ID

#### Scenario: Configure settingless walk-in customer
- **WHEN** an authorized user selects an existing walk-in customer whose `setting_id` is null
- **THEN** Settings SHALL accept and persist that customer ID

#### Scenario: Reject missing walk-in customer
- **WHEN** a submitted walk-in customer ID does not identify an existing customer
- **THEN** Settings validation SHALL reject the value

### Requirement: Sales import remains the protected reference contract
This normalization change MUST NOT alter sales-import customer parsing, global matching, caching, or persistence behavior. Non-import code SHALL support the customer record shape produced by sales import.

#### Scenario: Implement normalization without changing sales import
- **WHEN** the normalization change is implemented
- **THEN** `Modules/Sale/Services/SalesImportService.php` SHALL have no change attributable to this work
- **AND** customers with the sales-import record shape SHALL display and resolve correctly across normalized readers
