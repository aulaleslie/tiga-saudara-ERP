## ADDED Requirements

### Requirement: Tokenized global purchase-payment search
The system SHALL search standalone and supplier-embedded global purchase-payment results using the complete trimmed input as tokenized text criteria, with every non-empty whitespace-delimited token required to match at least one supported partial-search field on the same eligible purchase.

#### Scenario: Tokens match across different purchase fields
- **WHEN** a user enters multiple search tokens and each token partially matches at least one supported purchase field, supplier field, tag, or current linked-product name or code for the same eligible purchase
- **THEN** that purchase is included even when the tokens match different fields

#### Scenario: One token is absent
- **WHEN** an eligible purchase matches some but not all non-empty search tokens across its supported partial-search fields
- **THEN** that purchase is excluded

#### Scenario: Search composes with global workspace constraints
- **WHEN** tokenized search is used with supplier, business, date, summary-card, eligibility, sorting, or pagination constraints
- **THEN** every returned purchase satisfies both the search criteria and all active workspace constraints

### Requirement: Current product catalog is authoritative for global purchase text search
The system SHALL resolve product-name and product-code text matches from the current `products` row linked by each purchase detail and SHALL NOT use persisted purchase-detail product-name or product-code snapshots as a search fallback.

#### Scenario: Current product name differs from purchase snapshot
- **WHEN** an eligible purchase detail is linked to a product whose current name matches all applicable search tokens but whose persisted detail name does not
- **THEN** the purchase is included

#### Scenario: Only the purchase snapshot matches
- **WHEN** a search token matches only a persisted purchase-detail product name or code and does not match the current linked product or another supported field
- **THEN** the purchase is excluded

#### Scenario: Linked product is inactive
- **WHEN** an eligible purchase detail remains linked to an inactive or merged product whose current name or code matches the tokenized search
- **THEN** the purchase remains searchable by that current linked-product value

### Requirement: Exact barcode and serial search for global purchases
The system SHALL compare the complete trimmed search input against primary product barcodes, product-unit-conversion barcodes, and serial numbers using exact case-insensitive identity matching, and SHALL associate a match only with purchases linked to that product or serial provenance.

#### Scenario: Primary or conversion barcode matches exactly with different case
- **WHEN** the complete trimmed input differs only in letter case from a primary or unit-conversion barcode belonging to a product linked to an eligible purchase detail
- **THEN** that purchase is included

#### Scenario: Purchase receiving serial matches exactly with different case
- **WHEN** the complete trimmed input differs only in letter case from a serial associated with an eligible purchase through its receiving-detail provenance
- **THEN** that purchase is included

#### Scenario: Barcode or serial matches only partially
- **WHEN** the complete trimmed input is only a substring of a purchase product's barcode or receiving serial number and no tokenized text field matches
- **THEN** the purchase is excluded

#### Scenario: Same product without matching serial provenance
- **WHEN** a serial belongs to one purchase and another eligible purchase contains the same product without that serial provenance
- **THEN** only the purchase carrying the matched serial provenance is included by the serial search
