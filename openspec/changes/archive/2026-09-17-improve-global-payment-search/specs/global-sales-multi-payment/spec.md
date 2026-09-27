## ADDED Requirements

### Requirement: Tokenized global sales-payment search
The system SHALL search standalone and customer-embedded global sales-payment results using the complete trimmed input as tokenized text criteria, with every non-empty whitespace-delimited token required to match at least one supported partial-search field on the same eligible sale.

#### Scenario: Tokens match across different sale fields
- **WHEN** a user enters multiple search tokens and each token partially matches at least one supported sale field, customer field, tag, POS identifier, bundle name, or current linked-product name or code for the same eligible sale
- **THEN** that sale is included even when the tokens match different fields

#### Scenario: One token is absent
- **WHEN** an eligible sale matches some but not all non-empty search tokens across its supported partial-search fields
- **THEN** that sale is excluded

#### Scenario: Search composes with global workspace constraints
- **WHEN** tokenized search is used with customer, business, date, summary-card, eligibility, sorting, or pagination constraints
- **THEN** every returned sale satisfies both the search criteria and all active workspace constraints

### Requirement: Current product catalog is authoritative for global sales text search
The system SHALL resolve product-name and product-code text matches from the current `products` row linked by each sale detail and SHALL NOT use persisted sale-detail product-name or product-code snapshots as a search fallback.

#### Scenario: Current product name differs from sale snapshot
- **WHEN** an eligible sale detail is linked to a product whose current name matches all applicable search tokens but whose persisted detail name does not
- **THEN** the sale is included

#### Scenario: Only the sale snapshot matches
- **WHEN** a search token matches only a persisted sale-detail product name or code and does not match the current linked product or another supported field
- **THEN** the sale is excluded

#### Scenario: Linked product is inactive
- **WHEN** an eligible sale detail remains linked to an inactive or merged product whose current name or code matches the tokenized search
- **THEN** the sale remains searchable by that current linked-product value

### Requirement: Exact barcode and serial search for global sales
The system SHALL compare the complete trimmed search input against primary product barcodes, product-unit-conversion barcodes, and serial numbers using exact case-insensitive identity matching, and SHALL associate a match only with sales linked to that product or serial dispatch provenance.

#### Scenario: Primary or conversion barcode matches exactly with different case
- **WHEN** the complete trimmed input differs only in letter case from a primary or unit-conversion barcode belonging to a product linked to an eligible sale detail
- **THEN** that sale is included

#### Scenario: Dispatched serial matches exactly with different case
- **WHEN** the complete trimmed input differs only in letter case from a serial associated with an eligible sale through persisted sale or dispatch provenance
- **THEN** that sale is included

#### Scenario: Barcode or serial matches only partially
- **WHEN** the complete trimmed input is only a substring of a sale product's barcode or dispatched serial number and no tokenized text field matches
- **THEN** the sale is excluded

#### Scenario: Same product without matching serial provenance
- **WHEN** a serial was dispatched for one sale and another eligible sale contains the same product without that serial provenance
- **THEN** only the sale carrying the matched serial provenance is included by the serial search
